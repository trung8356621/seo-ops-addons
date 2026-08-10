<?php

declare(strict_types=1);

return [
    'none' => 'No Hook',
    'experimental_badge' => 'Experimental',
    'experimental_warning' => 'This Hook is experimental version :version.',
    'execution_failed_title' => 'Prompt Hook failed',
    'hook_template_owns_prompt' => 'When a Hook is selected, template and output contract come from the Hook Definition. Markdown on the right is used only when no Hook is attached.',
    'hook_legacy_prompt_template_note' => 'This Hook manages the input/output contract and runtime. The current Prompt content remains the template sent to the AI.',
    'input_mapping_hint' => 'Map workflow variables → Hook inputs (same {{field}} name unless noted).',

    'variables' => [
        'keyword' => 'Focus keyword',
        'old_title' => 'Current title',
        'title' => 'Article title',
        'old_description' => 'Current meta description',
        'focus_keyword' => 'Focus keyword',
        'outline' => 'Article outline',
        'section_content' => 'Current section content',
        'content_excerpt' => 'Content excerpt',
        'language' => 'Output language',
        'domain' => 'Domain',
        'post_title' => 'Article title',
        'post_excerpt' => 'Content excerpt',
        'post_type' => 'Post type',
        'site_short_description' => 'Website short description',
        'site_description' => 'Site description',
        'tone' => 'Tone of voice',
        'input' => 'Source content',
        'seed_topic' => 'Seed topic',
        'count' => 'Suggested count',
        'brief' => 'Brief',
    ],

    'article_title_suggestion' => [
        'label' => 'Article title suggestion',
        'description' => 'Suggest a new article title from the focus keyword and current title.',
        'presentation' => [
            'default_instructions' => [
                'Return exactly one article title as plain text.',
                'Stay close to the topic and focus keyword.',
                'If an old title is provided, improve it — do not copy it blindly.',
                'Keep the title within the configured maximum length when possible.',
                'Do not add explanations, prefixes, markdown, or wrapping quotes.',
            ],
            'output_format' => [
                'One complete title line only — no list and no extra commentary.',
            ],
            'notes' => [
                'Preserve-meaning and max-length settings on the Prompt Hook still apply.',
            ],
        ],
        'template' => <<<'PROMPT'
## Hook constraints — article title suggestion
- Return exactly one article title as plain text.
- Do not add explanations, prefixes (e.g. "Title:"), markdown, or quotes.
- Prefer including the focus keyword naturally: {{keyword}}
- If old_title is not null, treat it as context to improve — do not copy it blindly: {{old_title}}
- Keep the title within {{max_length}} characters when possible.
- preserve_meaning={{preserve_meaning}}
PROMPT,
        'settings' => [
            'max_length' => 'Maximum length',
            'preserve_meaning' => 'Preserve meaning of the current title',
        ],
    ],

    'article_meta_description_suggestion' => [
        'label' => 'SEO meta description suggestion',
        'description' => 'Suggest an SEO meta description from the article title and current description.',
        'presentation' => [
            'default_instructions' => [
                'Return exactly one meta description paragraph as plain text.',
                'Summarize the content accurately based on the title context.',
                'Include the topic naturally — do not keyword-stuff.',
                'Do not invent facts that are not present in the input.',
                'Target length between the configured minimum and maximum characters.',
            ],
            'output_format' => [
                'One complete meta description — no prefix, list, or explanation.',
            ],
        ],
        'template' => <<<'PROMPT'
## Hook constraints — SEO meta description suggestion
- Return exactly one meta description paragraph as plain text.
- Do not add explanations, prefixes (e.g. "Meta description:"), markdown, or quotes.
- Base the description on the title: {{title}}
- If old_description is not null, use it as context to improve: {{old_description}}
- Target length between {{min_length}} and {{max_length}} characters.
- Do not invent specific facts that are not present in the input.
PROMPT,
        'settings' => [
            'min_length' => 'Minimum length',
            'max_length' => 'Maximum length',
        ],
    ],

    'article_outline_generate' => [
        'label' => 'Generate article outline',
        'description' => 'Generate an article outline and writing vocabulary from the topic and keyword.',
        'presentation' => [
            'default_instructions' => [
                'Build a clear outline structure that matches search intent.',
                'Keep headings distinct — avoid overlapping ideas.',
                'Do not write the full article body in this step.',
                'Include semantic vocabulary / writing guidance in the second section.',
            ],
            'output_format' => [
                'Structured Markdown with two declared sections:',
                'Task 1 — Outline between the outline markers.',
                'Task 2 — Vocabulary / writing instructions between the vocabulary markers.',
            ],
            'notes' => [
                'Prompt Markdown is the template sent to the model for this Hook.',
            ],
        ],
    ],

    'article_content_generate' => [
        'label' => 'Write article',
        'description' => 'Generate a full article from outline, existing article, or brief (experimental 0.1.0). Bind here for Editor + Stable Gate; Content Project Publish still uses Workflow node Prompt.',
        'presentation' => [
            'default_instructions' => [
                'Generate full article markdown from outline, existing article, or brief (source_type).',
                'Respect outline structure when source is outline; do not invent conflicting facts.',
                'Settings binding is used by Editor full rewrite (source=existing_article) and direct generate.',
            ],
            'output_format' => [
                'Markdown article body (no surrounding code fence).',
            ],
            'notes' => [
                'Hook key: article.content.generate — Content Project Publish still uses Workflow node Prompt.',
                'Legacy article.content.rewrite is compatibility-only; do not bind new Prompts to it.',
            ],
        ],
    ],

    'article_content_rewrite' => [
        'label' => 'Rewrite article',
        'description' => 'Rewrite or improve existing content per instructions while preserving search intent and source facts (experimental 0.1.0).',
    ],

    'article_faq_generate' => [
        'label' => 'Generate article FAQ',
        'description' => 'Generate frequently asked questions from the article content.',
        'presentation' => [
            'default_instructions' => [
                'Questions must relate directly to the article content.',
                'Answers should be short and useful.',
                'Do not add facts that are not present in the source.',
                'Avoid duplicate questions that cover the same idea.',
            ],
            'output_format' => [
                'A JSON object: {"faqs":[{"question":"...","answer":"..."}, ...]}',
                'Readable shape per item: Question / Answer.',
                'No markdown fences and no free-form essay outside the JSON object.',
            ],
            'notes' => [
                'Domain persistence (saving FAQ to the article) stays in the caller — this Hook only generates content.',
            ],
        ],
    ],

    'article_featured_snippet_generate' => [
        'label' => 'Generate featured snippet',
        'description' => 'Generate a short answer block suitable for a featured snippet.',
        'presentation' => [
            'default_instructions' => [
                'Answer the main topic or question directly.',
                'Prefer information from the current section and outline.',
                'Keep the block concise and clear.',
                'Do not wrap the result in a full article.',
                'Do not add filler introductions or conclusions.',
            ],
            'output_format' => [
                'One featured-snippet-ready HTML block (definition, steps, bullets, or comparison table).',
                'Plain text/HTML content only — no surrounding article shell.',
            ],
        ],
    ],

    'article_content_translate' => [
        'label' => 'Translate article',
        'description' => 'Translate article content to the target language while preserving meaning and structure.',
        'presentation' => [
            'default_instructions' => [
                'Translate the source article into the target language.',
                'Do not change the meaning of the source.',
                'Preserve Markdown structure and heading hierarchy when present.',
                'Do not add commentary before or after the translation.',
            ],
            'output_format' => [
                'Translated article content only — no explanations.',
            ],
            'notes' => [
                'Prompt Markdown is the template sent to the model for this Hook.',
            ],
        ],
    ],

    'article_comment_generate' => [
        'label' => 'Generate article comments',
        'description' => 'Generate natural reader comments for seeding on an article.',
        'presentation' => [
            'default_instructions' => [
                'Write as a real reader, not as the author.',
                'Comments should add value or ask an open follow-up question.',
                'Email addresses must match the commenter name.',
                'Do not add greetings, titles, or extra explanations outside the comment lines.',
            ],
            'output_format' => [
                'One comment per line.',
                'Each line: Full name | Email | Comment text',
                'Only comment lines — no heading or surrounding prose.',
            ],
            'notes' => [
                'The Prompt Markdown sets how many comments to request.',
                'The parser accepts up to 10 valid lines and skips invalid ones.',
            ],
        ],
    ],

    'product_gallery_generate' => [
        'label' => 'Product gallery',
        'description' => 'Generate product gallery images using the bound image Prompt.',
        'presentation' => [
            'default_instructions' => [
                'Create images suitable for a product gallery album.',
                'Prefer clear layouts that work well on a product page.',
                'Follow the Prompt Markdown and image tool settings of the selected Prompt.',
            ],
            'output_format' => [
                'Image output from the selected image model / Prompt pipeline.',
            ],
            'notes' => [
                'This Hook covers gallery image generation only.',
                'It does not replace or rewrite the Product Gallery Description field.',
            ],
        ],
    ],

    'keyword_discovery_structured' => [
        'label' => 'Keyword discovery (JSON)',
        'description' => 'Propose structured keyword data for Content Project planning.',
        'presentation' => [
            'default_instructions' => [
                'Keep results on-topic for the seed topic and brief.',
                'Follow the structured JSON contract — no free-form essays.',
                'Avoid duplicate keywords.',
                'Return JSON only — no markdown fences.',
            ],
            'output_format' => [
                'A JSON object of structured keyword recommendations.',
                'Caller currently reads list entries as strings or objects with a keyword field.',
            ],
            'notes' => [
                'This Hook does not write keywords to the domain database — callers persist results.',
                'Field-level keys beyond keyword are not fixed in the Hook schema yet — keep the JSON object generic.',
            ],
        ],
    ],
];
