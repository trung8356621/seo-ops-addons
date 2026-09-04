<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate;

/**
 * Documentation worksheet for sample/template downloads only — not required at runtime.
 */
final class ExcelTemplateHelpSheetBuilder
{
    public const SHEET_NAME = '_HUONG_DAN';

    /**
     * @return list<list<scalar|null>>
     */
    public function rows(
        ExcelScalarVariableRegistry $scalars,
        ExcelTableVariableRegistry $tables,
    ): array {
        $rows = [];
        $rows[] = ['SEO OPS — HƯỚNG DẪN TEMPLATE EXCEL'];
        $rows[] = [''];
        $rows[] = ['Đây là sheet hướng dẫn. Sau khi hoàn tất file mẫu, có thể xóa sheet này trước khi tải template lên hệ thống.'];
        $rows[] = ['Xóa sheet này KHÔNG làm hỏng template. Hệ thống không phụ thuộc sheet hướng dẫn lúc export.'];
        $rows[] = [''];

        $rows[] = ['A. HƯỚNG DẪN NHANH'];
        $rows[] = ['- SEO Ops chỉ thay dữ liệu (scalar + bảng + sheet hệ thống STATS/DATA|writer).'];
        $rows[] = ['- User sở hữu formula, chart, dashboard và formatting.'];
        $rows[] = ['- Formula Excel (=...) được giữ nguyên — SEO Ops không parse/tính lại.'];
        $rows[] = ['- Sau khi thiết kế xong, xóa sheet hướng dẫn rồi upload workbook làm template.'];
        $rows[] = [''];

        $rows[] = ['B. BEGIN_SHEET'];
        $rows[] = ['BEGIN_SHEET = số sheet do user kiểm soát nằm TRƯỚC các sheet hệ thống (STATS, DATA / writer).'];
        $rows[] = ['Cấu hình trong template: named range BEGIN_SHEET, custom property, hoặc ô khóa BEGIN_SHEET + giá trị cạnh/dưới.'];
        $rows[] = ['Ví dụ BEGIN_SHEET = 5 (sau khi đã xóa sheet hướng dẫn):'];
        $rows[] = ['1 DASHBOARD'];
        $rows[] = ['2 KPI'];
        $rows[] = ['3 CALC'];
        $rows[] = ['4 CHECKLIST SEO'];
        $rows[] = ['5 BÁO CÁO'];
        $rows[] = ['----------------'];
        $rows[] = ['6 STATS'];
        $rows[] = ['7 DATA / writer sheets'];
        $rows[] = ['Lưu ý: sheet hướng dẫn chỉ có trong file mẫu. Đếm BEGIN_SHEET trên workbook bạn upload (không gồm _HUONG_DAN nếu đã xóa).'];
        $rows[] = [''];

        $rows[] = ['C. SCALAR VARIABLES'];
        $rows[] = ['Variable', 'Ý nghĩa', 'Ví dụ (minh họa — không phải dữ liệu live)'];
        $examples = [
            'month' => '07/2026',
            'year' => '2026',
            'articles.total' => 123,
            'articles.archived' => 123,
            'articles.indexed' => 45,
            'articles.not_indexed' => 78,
            'articles.index_rate' => 36.6,
            'project.total' => 12,
            'export.generated_at' => '2026-09-04 15:00:00',
        ];
        foreach ($scalars->all() as $def) {
            $rows[] = [
                $def->placeholder(),
                $def->description !== '' ? $def->description : $def->label,
                $examples[$def->key] ?? '',
            ];
        }
        $rows[] = [''];

        $rows[] = ['D. TABLE / BLOCK VARIABLES'];
        $rows[] = ['Biến bảng mở rộng từ ô chứa placeholder sang phải và xuống dưới (top-left anchor).'];
        $rows[] = ['Ví dụ F1 = {{table.articles_by_domain}} → ghi cả khối bảng bắt đầu tại F1.'];
        $rows[] = ['Variable', 'Ý nghĩa', 'Cột dữ liệu'];
        foreach ($tables->all() as $def) {
            $rows[] = [
                $def->placeholder(),
                $def->label,
                implode(' | ', $def->columns),
            ];
        }
        $rows[] = [''];

        $rows[] = ['E. FORMULA EXAMPLE'];
        $rows[] = ['B2', '{{articles.indexed}}', 'SEO Ops thay bằng số'];
        $rows[] = ['B3', '{{articles.total}}', 'SEO Ops thay bằng số'];
        $rows[] = ['B4', '=B2/B3', 'Giữ nguyên formula Excel'];
        $rows[] = [''];
        $rows[] = ['Cấu trúc file mẫu (RAW):'];
        $rows[] = ['- BY_WRITER_SHEET: _HUONG_DAN | STATS | _WRITER_TEMPLATE (2 hàng header, không có dòng bài)'];
        $rows[] = ['- SINGLE_DATA_SHEET: _HUONG_DAN | STATS | DATA (2 hàng header, không có dòng bài)'];
        $rows[] = ['Export production nhân bản _WRITER_TEMPLATE theo từng người viết / đổ dòng vào DATA từ hàng 3.'];
        $rows[] = [''];

        $rows[] = ['F. HEADING VÀ MÃ CỘT'];
        $rows[] = ['Mỗi sheet dữ liệu chi tiết (_WRITER_TEMPLATE / DATA / sheet người viết) có HAI hàng header:'];
        $rows[] = ['- Hàng 1 = nhãn hiển thị (user được đổi tên trình bày).'];
        $rows[] = ['- Hàng 2 = mã hệ thống SEO Ops (system column code) — không đổi nếu vẫn muốn hệ thống nhận diện cột.'];
        $rows[] = ['- Hàng 3 trở đi = dữ liệu production.'];
        $rows[] = [''];
        $rows[] = ['Backend map cột theo mã (hàng 2), KHÔNG theo vị trí cột, chữ cái Excel, hay nhãn tiếng Việt.'];
        $rows[] = ['Muốn đổi thứ tự cột: kéo CẢ nhãn (hàng 1) và mã (hàng 2) cùng nhau.'];
        $rows[] = ['Không được trùng một mã hệ thống trên cùng một sheet.'];
        $rows[] = ['Cột tự thêm (ghi chú, formula) nếu không có mã hệ thống hợp lệ sẽ được giữ nguyên — SEO Ops không ghi đè.'];
        $rows[] = ['Có thể xóa cột tùy chọn không cần thiết; export sẽ bỏ qua field đó.'];
        $rows[] = [''];
        $rows[] = ['Ví dụ — TRƯỚC:'];
        $rows[] = ['Dự án', 'Domain', 'Bài viết'];
        $rows[] = ['project_name', 'domain', 'article_title'];
        $rows[] = ['Ví dụ — SAU KHI ĐỔI THỨ TỰ (vẫn hợp lệ):'];
        $rows[] = ['Domain', 'Bài viết', 'Dự án'];
        $rows[] = ['domain', 'article_title', 'project_name'];

        return $rows;
    }
}
