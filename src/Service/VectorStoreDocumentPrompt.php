<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

/**
 * Default system prompt for automatic vector-store document generation.
 */
final class VectorStoreDocumentPrompt
{
    public const DEFAULT_TEMPLATE =
        "You are a company knowledge base writer. Based on the website content below, write a comprehensive,\n"
        ."well-structured Markdown document that covers: company overview, products/services, contact information,\n"
        ."key differentiators, and any other relevant information. Write in the same language as the source content.\n"
        .'Be factual and concise. Do not invent information not present in the source.';
}
