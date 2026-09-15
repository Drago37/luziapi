<?php

/**
 * Programme de fidélité : règlement versionné, URLs publiques et chiffres du programme.
 *
 * Le règlement est conservé sur support durable, sur le même principe que les CGV
 * (une version datée, un PDF immuable de même millésime dans assets/docs/).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const LUZIAPI_LOYALTY_REGLEMENT_VERSION = '2026-09-14-v1';
const LUZIAPI_LOYALTY_REGLEMENT_LABEL   = 'Version du 14 septembre 2026 — révision 1';
const LUZIAPI_LOYALTY_START_LABEL       = '14 septembre 2024';
const LUZIAPI_LOYALTY_REGLEMENT_PDF     = 'LuziApi-Reglement-Fidelite-' . LUZIAPI_LOYALTY_REGLEMENT_VERSION . '.pdf';

/**
 * URL publique de la page « Programme de fidélité », même avant sa création en base.
 */
function luziapi_loyalty_page_url(): string
{
    if (function_exists('luziapi_legal_page_url')) {
        return luziapi_legal_page_url('programme-de-fidelite');
    }

    return home_url('/programme-de-fidelite/');
}

/**
 * URL du PDF immuable du règlement, uniquement s'il a été déposé dans assets/docs/.
 * Tant que le PDF n'existe pas, la chaîne vide masque le bouton de téléchargement.
 */
function luziapi_loyalty_reglement_pdf_url(): string
{
    $path = LUZIAPI_DIR . '/assets/docs/' . LUZIAPI_LOYALTY_REGLEMENT_PDF;

    return is_readable($path)
        ? LUZIAPI_URI . '/assets/docs/' . LUZIAPI_LOYALTY_REGLEMENT_PDF
        : '';
}

/**
 * Chiffres du programme lus depuis le domaine (jamais codés en dur dans les templates),
 * afin que le texte client suive toujours la règle réellement appliquée par le moteur.
 *
 * @return array{
 *     pots_per_reward: int,
 *     start_label: string,
 *     reglement_label: string,
 *     reglement_version: string
 * }
 */
function luziapi_loyalty_program_numbers(): array
{
    // La classe du domaine est la source unique du seuil : le texte client suit
    // toujours la règle réellement appliquée par le moteur. Les pots n'expirent pas.
    return [
        'pots_per_reward'    => \LuziApi\Loyalty\Domain\LoyaltyProgress::potsPerReward(),
        'start_label'        => LUZIAPI_LOYALTY_START_LABEL,
        'reglement_label'    => LUZIAPI_LOYALTY_REGLEMENT_LABEL,
        'reglement_version'  => LUZIAPI_LOYALTY_REGLEMENT_VERSION,
    ];
}
