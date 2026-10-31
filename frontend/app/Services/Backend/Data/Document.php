<?php

namespace App\Services\Backend\Data;

use Carbon\CarbonImmutable;

/**
 * Un documento indicizzato nella collection dell'agente.
 */
final readonly class Document
{
    /**
     * @param  bool  $replaced  solo nella risposta dell'upload: il file ha sostituito un documento con lo stesso id
     */
    public function __construct(
        public string $documentId,
        public string $filename,
        public int $chunks,
        public int $sizeBytes,
        public CarbonImmutable $uploadedAt,
        public bool $replaced = false,
    ) {}

    /**
     * @param  array{document_id: string, filename: string, chunks: int, size_bytes: int, uploaded_at: string, replaced?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            documentId: $data['document_id'],
            filename: $data['filename'],
            chunks: $data['chunks'],
            sizeBytes: $data['size_bytes'],
            uploadedAt: CarbonImmutable::parse($data['uploaded_at']),
            replaced: $data['replaced'] ?? false,
        );
    }
}
