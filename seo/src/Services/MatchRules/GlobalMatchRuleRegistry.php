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
            'cta_action_terms' => $this->rule('cta_action_terms', 'CTA action terms', 'Generic action terms commonly leading CTA phrases.', ['nhận', 'liên hệ', 'đăng ký', 'gọi', 'xem', 'tìm hiểu', 'điền', 'bắt đầu', 'click', 'contact', 'get', 'request', 'read more', 'sign up']),
            'cta_phrase_terms' => $this->rule('cta_phrase_terms', 'CTA phrase terms', 'Generic multi-word calls to action.', ['liên hệ ngay', 'nhận tư vấn', 'đăng ký nhận', 'gọi ngay', 'xem thêm', 'tìm hiểu thêm', 'điền form', 'contact us', 'get quote', 'request quote']),
            'cta_urgency_terms' => $this->rule('cta_urgency_terms', 'CTA urgency terms', 'Generic urgency and incentive language.', ['ngay', 'miễn phí', 'here', 'now']),
            'sentence_hints' => $this->rule('sentence_hints', 'Keyword sentence hints', 'Language hints used to identify sentence-like keyword candidates.', ['chúng tôi', 'công ty chúng tôi', 'có thể', 'mang lại', 'khách hàng', 'được', 'giúp', 'sẽ', 'đang', 'nên', 'cần']),
            'marketing_terms' => $this->rule('marketing_terms', 'Marketing terms', 'Generic promotional language.', ['đơn vị', 'uy tín', 'chuyên nghiệp', 'hàng đầu', 'chất lượng', 'đáng tin', 'cam kết', 'tận tâm']),
            'location_terms' => $this->rule('location_terms', 'Location terms', 'Generic location wrappers and common market locations.', ['tại', 'tp', 'tphcm', 'hồ chí minh', 'hcm', 'hà nội', 'đà nẵng']),
            'question_terms' => $this->rule('question_terms', 'Question terms', 'Generic question words and phrases.', ['gì', 'sao', 'như thế nào', 'ở đâu', 'làm sao', 'tại sao', 'how', 'what', 'where', 'why']),
            'transactional_terms' => $this->rule('transactional_terms', 'Transactional terms', 'Generic purchase and transaction intent.', ['mua', 'đặt hàng', 'order', 'buy', 'báo giá', 'thanh toán', 'giá', 'chi phí', 'bảng giá']),
            'informational_terms' => $this->rule('informational_terms', 'Informational terms', 'Generic informational intent.', ['là gì', 'hướng dẫn', 'cách', 'what is', 'how to']),
            'commercial_lead_terms' => $this->rule('commercial_lead_terms', 'Commercial lead terms', 'Generic commercial terms allowed before an industry entity.', ['báo giá', 'giá', 'mua', 'cách']),
            'generic_purchase_terms' => $this->rule('generic_purchase_terms', 'Generic purchase terms', 'Short generic purchase vocabulary used by brand/entity scoring.', ['giá', 'mua']),
            'topic_glue_terms' => $this->rule('topic_glue_terms', 'Topic glue terms', 'Language connectors ignored by structural Topic matching.', ['tai', 'o', 'cho', 'la', 'cua', 'va', 'voi', 'den', 'tu', 'trong', 'theo']),
            'discourse_prefixes' => $this->rule('discourse_prefixes', 'Discourse prefixes', 'Generic prefixes stripped when resolving Topic phrases.', ['tham khao', 'tim hieu', 'xem them', 'thong tin ve', 'thong tin']),
            'topic_location_wrappers' => $this->rule('topic_location_wrappers', 'Topic location wrappers', 'Location wrappers stripped from Topic DNA display.', ['tai', 'o', 'tai thanh pho', 'o thanh pho']),
            'topic_question_tokens' => $this->rule('topic_question_tokens', 'Topic question tokens', 'Question heads preserved by Topic DNA grammar.', ['gi', 'ai', 'dau', 'sao', 'nao', 'bao', 'khi']),
            'link_heading_prefixes' => $this->rule('link_heading_prefixes', 'Internal link heading prefixes', 'Prefixes stripped from heading-derived content phrase candidates.', ['huong dan', 'hướng dẫn', 'cach', 'cách', 'tai sao', 'tại sao', 'luu y', 'lưu ý', 'cac', 'các', 'nhung', 'những', 'top', 'so sanh', 'so sánh', 'bang', 'bảng', 'gioi thieu', 'giới thiệu', 'tong quan', 'tổng quan', 'loi ich', 'lợi ích', 'uu diem', 'ưu điểm', 'nhuoc diem', 'nhược điểm', 'ket luan', 'kết luận', 'tom tat', 'tóm tắt', 'danh sach', 'danh sách', 'how to', 'why', 'what is', 'what are', 'best', 'guide', 'tips']),
            'link_phrase_connectors' => $this->rule('link_phrase_connectors', 'Internal link phrase connectors', 'Connector terms excluded when accepting content phrase candidates.', ['va', 'cua', 'cho', 'voi', 'tu', 've', 'de', 'khi', 'neu', 'hoac', 'nhung', 'la', 'ma', 'thi', 'bi', 'duoc', 'cac', 'nhung', 'mot', 'nhung']),
            'link_ngram_leading_stopwords' => $this->rule('link_ngram_leading_stopwords', 'Internal link ngram leading stopwords', 'Weak leading terms rejected from repeated ngram candidates.', ['cac', 'nhung', 'mot', 'các', 'những', 'một']),
            'link_phrase_stopwords' => $this->rule('link_phrase_stopwords', 'Internal link phrase stopwords', 'Terms used to reject content phrase candidates containing stopwords only.', ['va', 'và', 'cua', 'của', 'cho', 'voi', 'với', 'la', 'là', 'cac', 'các', 'mot', 'một', 'the', 'and', 'or', 'to', 'in', 'on', 'of', 'for', 'a', 'an', 'nhung', 'những', 'nhu', 'như', 'de', 'để', 'khi', 'nay', 'này']),
        ];
    }

    /** @return array{key:string,label:string,scope:string,description:string,match_mode:string,editable:bool,defaults:list<string>} */
    private function rule(string $key, string $label, string $description, array $defaults): array
    {
        return compact('key', 'label', 'description', 'defaults') + ['scope' => 'global', 'match_mode' => 'phrase', 'editable' => true];
    }
}
