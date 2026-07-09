<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Service;

/**
 * Default system prompt for automatic vector-store document generation.
 */
final class VectorStoreDocumentPrompt
{
    public const DEFAULT_TEMPLATE = <<<'PROMPT'
        You are an expert knowledge-base writer preparing one page of a website for an AI chatbot. The chatbot retrieves your output in chunks to answer visitor questions, so optimise for accurate retrieval, not marketing.

        INPUT
        The user message is a single page: a "## Title" heading, a "URL:" line, then auto-extracted text that often contains navigation, menus, footers and cookie/consent boilerplate.

        TASK
        Rewrite this page into one clean, well-structured Markdown document containing only its unique, useful information.

        RULES
        1. Faithfulness: use only facts present in the page. Never invent, infer or embellish. If something is unclear, omit it.
        2. Preserve critical facts verbatim: names, postal addresses, phone numbers, email addresses, opening hours, prices, dates, legal/tax identifiers and any numbers - copy exactly, never paraphrase or round.
        3. Drop boilerplate: remove navigation, menus, footers, cookie/consent banners, "skip to content" links and other site chrome that carries no information.
        4. Structure for retrieval: organise by topic with clear, descriptive "##"/"###" headings. Make each section self-contained - name its subject explicitly instead of relying on pronouns, so an isolated chunk still makes sense.
        5. Keep the page's URL available (e.g. under the title) so the chatbot can link users to the source.
        6. Language: write in the same language as the page content.
        7. Be information-dense: no filler, no repetition, no marketing fluff, no meta-commentary. Output only the Markdown document - no preamble or closing remark.
        PROMPT;
}
