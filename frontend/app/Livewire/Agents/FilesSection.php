<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Services\Backend\BackendClient;
use App\Services\Backend\BackendException;
use App\Support\DemoAccount;
use App\Support\UsageLimits;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Quello che hanno in comune le sezioni Documenti e Tabelle: upload di un file al
 * backend, elenco letto dal backend, eliminazione confermata in un modal.
 *
 * L'agente demo si consulta soltanto: i suoi file li carica lo script del backend.
 * I caricamenti hanno limiti di costo per utente, più bassi nelle sandbox della demo
 * (App\Support\UsageLimits).
 *
 * Il file scelto arriva prima nella cartella temporanea di Livewire: lì resta solo
 * il tempo di mandarlo al backend, poi si cancella comunque sia andata.
 */
abstract class FilesSection extends Component
{
    use WithFileUploads;

    #[Locked]
    public Agent $agent;

    public ?TemporaryUploadedFile $file = null;

    /** Cambia ogni volta che il file si scarta, per svuotare il campo file (che Flux non ridisegna). */
    public int $fileInputKey = 0;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?string $deletingId = null;

    #[Locked]
    public string $deletingName = '';

    public ?string $deleteError = null;

    protected BackendClient $backend;

    /**
     * Le estensioni ammesse, senza punto.
     *
     * @return list<string>
     */
    abstract protected function extensions(): array;

    /**
     * Carica il file e restituisce il messaggio di conferma per l'utente.
     *
     * @throws BackendException
     */
    abstract protected function store(TemporaryUploadedFile $file): string;

    /**
     * Elimina dal backend il documento o la tabella con questo id.
     *
     * @throws BackendException
     */
    abstract protected function destroy(string $id): void;

    /**
     * La regex di docs/api.md per l'id di un documento o di una tabella.
     */
    abstract protected function idPattern(): string;

    /**
     * Quanti documenti o tabelle ha già l'agente, letti dal backend.
     *
     * @throws BackendException
     */
    abstract protected function storedCount(): int;

    /**
     * Quanti documenti o tabelle può avere l'agente: meno nelle sandbox della demo.
     */
    abstract protected function maxFiles(): int;

    /**
     * Il messaggio per una sandbox che ha già maxFiles() file.
     */
    abstract protected function limitReachedMessage(): string;

    public function boot(BackendClient $backend): void
    {
        $this->backend = $backend;
    }

    public function mount(Agent $agent): void
    {
        $this->authorize('view', $agent);

        $this->agent = $agent;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // L'estensione e non il tipo MIME, che per .md e .csv è un generico text/plain:
                // che il contenuto corrisponda all'estensione lo controlla il backend.
                'extensions:'.implode(',', $this->extensions()),
                'max:'.$this->maxUploadMb() * 1024,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'file.required' => 'Scegli un file da caricare.',
            'file.file' => 'Il file non è arrivato: prova a sceglierlo di nuovo.',
            'file.extensions' => "Formato non ammesso: carica un file {$this->allowedExtensions()}.",
            'file.max' => "Il file supera il limite di {$this->maxUploadMb()} MB.",
        ];
    }

    /**
     * Un file rifiutato (formato, dimensione) non resta nella cartella temporanea.
     */
    public function updatedFile(): void
    {
        try {
            $this->validateOnly('file');
        } catch (ValidationException $e) {
            $this->discardFile();

            throw $e;
        }
    }

    /**
     * Se il backend rifiuta il file (formato, firma, limite raggiunto) il motivo
     * compare sotto il campo, come un errore di validazione.
     */
    public function upload(): void
    {
        $this->authorize('manageFiles', $this->agent);

        try {
            $this->validate();

            $reason = $this->limitReason();

            if ($reason !== null) {
                $this->addError('file', $reason);

                return;
            }

            Flux::toast(variant: 'success', text: $this->store($this->file));
        } catch (BackendException $e) {
            $this->addError('file', $e->getMessage());
        } finally {
            // Anche dopo un errore: un documento eliminato non deve restare sul server.
            $this->discardFile();
        }
    }

    /**
     * L'id arriva dal browser e finisce nell'URL del backend: solo la forma di docs/api.md.
     */
    public function confirmDelete(string $id, string $name): void
    {
        $this->authorize('manageFiles', $this->agent);

        abort_unless(preg_match($this->idPattern(), $id) === 1, 404);

        $this->deletingId = $id;
        $this->deletingName = $name;
        $this->deleteError = null;
        $this->showDeleteModal = true;
    }

    /**
     * Se il backend non riesce a eliminare, il modal resta aperto con il motivo.
     */
    public function delete(): void
    {
        $this->authorize('manageFiles', $this->agent);

        if ($this->deletingId === null) {
            return;
        }

        try {
            $this->destroy($this->deletingId);
        } catch (BackendException $e) {
            $this->deleteError = $e->getMessage();

            return;
        }

        Flux::toast(variant: 'success', text: "Hai eliminato «{$this->deletingName}».");

        $this->closeDeleteModal();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
        $this->deleteError = null;
    }

    /**
     * Legge l'elenco dal backend a ogni richiesta, così riflette upload ed eliminazioni.
     * Se il backend non risponde la sezione si disegna lo stesso, con il motivo.
     *
     * @template T
     *
     * @param  callable(): list<T>  $fetch
     * @return array{0: list<T>, 1: string|null} l'elenco e l'eventuale errore
     */
    protected function load(callable $fetch): array
    {
        try {
            return [$fetch(), null];
        } catch (BackendException $e) {
            return [[], $e->getMessage()];
        }
    }

    /**
     * I dati comuni alle due viste: limiti e formati ammessi.
     *
     * @return array{maxUploadMb: int, accept: string, allowedExtensions: string}
     */
    protected function uploadLimits(): array
    {
        return [
            'maxUploadMb' => $this->maxUploadMb(),
            'accept' => implode(',', $this->dottedExtensions()),
            'allowedExtensions' => $this->allowedExtensions(),
        ];
    }

    /**
     * @return list<string>
     */
    protected function configuredExtensions(string $key): array
    {
        return array_values(array_filter(Config::array($key), is_string(...)));
    }

    /**
     * Il motivo per non caricare, o null. Le sandbox hanno meno file per agente del
     * backend (a limite pieno anche una sostituzione chiede di eliminare prima il file
     * vecchio); per tutti contano i caricamenti all'ora e al giorno, ma solo quelli che
     * partono davvero.
     */
    private function limitReason(): ?string
    {
        $user = Auth::user();

        if (DemoAccount::isSandbox($user)) {
            try {
                if ($this->storedCount() >= $this->maxFiles()) {
                    return $this->limitReachedMessage();
                }
            } catch (BackendException $e) {
                return $e->getMessage();
            }
        }

        return UsageLimits::hitUpload($user);
    }

    private function discardFile(): void
    {
        if ($this->file !== null) {
            $this->file->delete();

            // Accanto al file Livewire salva i metadati (nome originale, tipo), che delete() lascia.
            FileUploadConfiguration::storage()->delete(FileUploadConfiguration::path($this->file->getFilename().'.json', false));
        }

        $this->reset('file');
        $this->fileInputKey++;
    }

    private function maxUploadMb(): int
    {
        return UsageLimits::maxUploadMb(Auth::user());
    }

    /**
     * @return list<string>
     */
    private function dottedExtensions(): array
    {
        return array_map(fn (string $extension) => ".{$extension}", $this->extensions());
    }

    /**
     * ".txt, .md, .pdf, .docx o .html"
     */
    private function allowedExtensions(): string
    {
        $extensions = $this->dottedExtensions();
        $last = array_pop($extensions);

        return $extensions === [] ? (string) $last : implode(', ', $extensions)." o {$last}";
    }
}
