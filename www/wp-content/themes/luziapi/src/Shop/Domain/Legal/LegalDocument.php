<?php

declare(strict_types=1);

namespace LuziApi\Shop\Domain\Legal;

/**
 * Document légal versionné (CGV, règlement de fidélité…) conservé sur support
 * durable : une version courante, un libellé, et un PDF immuable par millésime.
 */
final readonly class LegalDocument
{
    public function __construct(
        private string $currentVersion,
        private string $label,
        private string $pdfPrefix,
    ) {
    }

    public function currentVersion(): string
    {
        return $this->currentVersion;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function currentPdfBasename(): string
    {
        return $this->pdfBasename($this->currentVersion);
    }

    /**
     * Nom du PDF pour la version acceptée par un client (figée dans la commande).
     * Une version absente ou au format inattendu retombe sur la version courante,
     * afin de ne jamais construire un chemin de fichier à partir d'une entrée
     * douteuse.
     */
    public function pdfBasenameForAcceptedVersion(string $acceptedVersion): string
    {
        $version = trim($acceptedVersion);
        if ('' === $version || 1 !== preg_match('/^[a-zA-Z0-9._-]+$/', $version)) {
            $version = $this->currentVersion;
        }

        return $this->pdfBasename($version);
    }

    private function pdfBasename(string $version): string
    {
        return $this->pdfPrefix . $version . '.pdf';
    }
}
