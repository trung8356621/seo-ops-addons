MARKDOWN ARTICLE OUTPUT CONTRACT

Return only the requested article content as valid Markdown.
Do not wrap the response in a Markdown code fence.
Do not output HTML.

Heading hierarchy:

- The article title is stored separately. Do not output an H1 heading (`#`) unless the task explicitly requests one.
- Use `##` for every main article section.
- Use `###` only for a subsection belonging to the nearest `##`.
- Use `####` only when a genuinely deeper subsection is required.
- Never skip heading levels.
- Never use `###` for a main section.
- Preserve the heading levels and heading text supplied by the outline.
- Do not convert a heading into bold text.
- Do not manually number headings unless the supplied outline already requires numbered headings.
- Do not add labels such as "Main Content", "Article Body", "Body", or "Content".
- Do not create labels such as `4. **Main Content:**`.

Formatting:

- Use `-` for unordered lists.
- Use `1.` for ordered lists.
- Use standard Markdown table syntax for tables.
- Use `**text**` only for emphasis, not as a heading substitute.
- Avoid decorative separators unless semantically necessary.
- Do not add explanations before or after the article.
