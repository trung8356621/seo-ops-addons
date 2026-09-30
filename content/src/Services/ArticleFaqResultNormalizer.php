<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

/** Normalizes the structured result produced by article.faq.generate. */
final class ArticleFaqResultNormalizer
{
    /** @return list<array{question: string, answer: string}> */
    public function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $json = trim($value);
            if ($json === '') {
                throw new \InvalidArgumentException('FAQ_EMPTY_RESULT: AI returned an empty FAQ result.');
            }
            try {
                $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('FAQ_INVALID_JSON: AI FAQ output is not valid JSON.', 0, $exception);
            }
        }

        if (! is_array($value)) {
            throw new \InvalidArgumentException('FAQ_INVALID_SCHEMA: AI FAQ output must be an object containing faqs.');
        }

        $rows = array_key_exists('faqs', $value) ? $value['faqs'] : $value;
        if (! is_array($rows)) {
            throw new \InvalidArgumentException('FAQ_INVALID_SCHEMA: The faqs field must be an array.');
        }

        $faqs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $faqs[] = ['question' => $question, 'answer' => $answer];
        }

        if ($faqs === []) {
            throw new \InvalidArgumentException('FAQ_EMPTY_RESULT: AI returned no valid FAQ items.');
        }

        return $faqs;
    }
}
