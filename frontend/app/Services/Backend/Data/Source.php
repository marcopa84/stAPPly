<?php

namespace App\Services\Backend\Data;

/**
 * Un chunk citato nella risposta, tra quelli restituiti da cerca_documenti nel turno.
 */
final readonly class Source
{
    public function __construct(
        public string $id,
        public string $documentId,
        public string $filename,
        public string $text,
    ) {}

    /**
     * @param  array{id: string, document_id: string, filename: string, text: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['document_id'], $data['filename'], $data['text']);
    }

    /**
     * La stessa forma del contratto, per salvarlo e rileggerlo con fromArray().
     *
     * @return array{id: string, document_id: string, filename: string, text: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->documentId,
            'filename' => $this->filename,
            'text' => $this->text,
        ];
    }
}
