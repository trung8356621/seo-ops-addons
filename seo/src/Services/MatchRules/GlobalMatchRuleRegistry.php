<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MatchRules;

final class GlobalMatchRuleRegistry
{
    /** @return array<string, array{key:string,label:string,scope:string,description:string,match_mode:string,editable:bool,defaults:list<string>}> */
    public function definitions(): array
    {
        return [
            'cta_blacklist' => $this->rule('cta_blacklist', 'CTA / Noise', 'Phrases that indicate calls to action or non-keyword noise.', ['tại đây', 'click vào', 'yêu cầu mẫu', 'liên hệ hotline', 'catalogue mẫu', 'miễn phí tại đây', 'yêu cầu catalogue', 'nhấn vào đây', 'xem thêm tại đây', 'liên hệ ngay']),
            'sentence_hints' => $this->rule('sentence_hints', 'Keyword sentence hints', 'Language hints used to identify sentence-like keyword candidates.', ['chúng tôi', 'công ty chúng tôi', 'có thể', 'mang lại', 'khách hàng', 'được', 'giúp', 'sẽ', 'đang', 'nên', 'cần']),
            'marketing_terms' => $this->rule('marketing_terms', 'Marketing terms', 'Generic promotional language.', ['đơn vị', 'uy tín', 'chuyên nghiệp', 'hàng đầu', 'chất lượng', 'đáng tin', 'cam kết', 'tận tâm']),
            'location_terms' => $this->rule('location_terms', 'Location terms', 'Generic location wrappers and common market locations.', ['tại', 'tp', 'tphcm', 'hồ chí minh', 'hcm', 'hà nội', 'đà nẵng']),
            'question_terms' => $this->rule('question_terms', 'Question terms', 'Generic question words and phrases.', ['gì', 'sao', 'như thế nào', 'ở đâu', 'làm sao', 'tại sao', 'how', 'what', 'where', 'why']),
            'transactional_terms' => $this->rule('transactional_terms', 'Transactional terms', 'Generic purchase and transaction intent.', ['mua', 'đặt hàng', 'order', 'buy', 'báo giá', 'thanh toán', 'giá', 'chi phí', 'bảng giá']),
            'informational_terms' => $this->rule('informational_terms', 'Informational terms', 'Generic informational intent.', ['là gì', 'hướng dẫn', 'cách', 'what is', 'how to']),
            'topic_glue_terms' => $this->rule('topic_glue_terms', 'Topic glue terms', 'Language connectors ignored by structural Topic matching.', ['tai', 'o', 'cho', 'la', 'cua', 'va', 'voi', 'den', 'tu', 'trong', 'theo']),
            'discourse_prefixes' => $this->rule('discourse_prefixes', 'Discourse prefixes', 'Generic prefixes stripped when resolving Topic phrases.', ['tham khao', 'tim hieu', 'xem them', 'thong tin ve', 'thong tin']),
        ];
    }

    /** @return array{key:string,label:string,scope:string,description:string,match_mode:string,editable:bool,defaults:list<string>} */
    private function rule(string $key, string $label, string $description, array $defaults): array
    {
        return compact('key', 'label', 'description', 'defaults') + ['scope' => 'global', 'match_mode' => 'phrase', 'editable' => true];
    }
}
