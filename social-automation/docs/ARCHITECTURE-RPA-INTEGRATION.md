# Social Automation — Kiến trúc và quy trình chuẩn

**Ngày chốt:** 22/10/2026  
**Trạng thái:** Architecture Approved — Implementation Pending  
**Phạm vi:** RPA Admin v2 → SEO-OPS Social Automation ↔ RPA Client

## 1. Mục tiêu kiến trúc

Hệ thống gồm ba thành phần độc lập:

### RPA Admin v2 — Script Creator

Công cụ dành cho developer:

- Tạo và chỉnh sửa kịch bản (Script/Scenario).
- Thiết kế Steps/Actions.
- Khai báo Variables và Outputs.
- Test Lab để kiểm thử trên browser thật.
- Quay video demo thể hiện kịch bản thực hiện những gì.
- Xuất và phát hành Script đã kiểm thử.

RPA Admin không chịu trách nhiệm quản lý Widget, Workflow nghiệp vụ, AI content, Task hay Scheduler.

RPA Admin chỉ được sử dụng khi cần tạo Script mới, sửa lỗi hoặc cập nhật Script do môi trường và giao diện social thay đổi.

### SEO-OPS — Social Automation Service

Là trung tâm quản lý và điều phối.

Trách nhiệm:

- Script Registry.
- Widget/Form Builder.
- Workflow Builder.
- Manual Mode.
- AI Auto Mode.
- Task/Scheduler.
- Agent/Account/Session Registry.
- Job Distribution.
- Job History và Artifacts.
- Service entitlement.

SEO-OPS không thực thi thao tác browser automation và không cần hiểu logic click, ROI hoặc DOM của Script.

### RPA Client / Runner

Là ứng dụng chạy trên máy người tham gia hệ thống.

Trách nhiệm:

- Đăng ký máy.
- Đồng bộ browser sessions.
- Đồng bộ platform account identities.
- Heartbeat.
- Nhận Job.
- Thực thi Script trên browser.
- Báo tiến độ, kết quả và lỗi.
- Gửi screenshot/artifacts.

RPA Client chưa được xây dựng tại thời điểm chốt kiến trúc.

---

## 2. Hai luồng giao tiếp

### Luồng 1: RPA Admin ↔ SEO-OPS Script Registry

**Catalog Script được đồng bộ hai chiều** giữa RPA Admin và SEO-OPS (push/pull/skip/conflict).

Tuy nhiên ranh giới trách nhiệm không đổi:

- **RPA Admin** là nơi author / test Script chính (Creator, Test Lab, video demo).
- **SEO-OPS** quản lý Script Registry, Widget, Workflow, nội dung nghiệp vụ và điều phối Job.
- **RPA Client** là executor (chưa triển khai trong phase hiện tại).
- Browser Sessions của RPA Admin luôn **local** — không sync cookies/profiles/credentials lên SEO-OPS.
- Browser Sessions của RPA Client mới sync về SEO-OPS khi Runner có sẵn.

Đồng bộ Script:

- Local có / server thiếu → push.
- Server có / local thiếu → pull/import (phải materialize Flow/Steps/assets; thiếu ROI → incomplete).
- Cùng digest → skip.
- Khác digest có lineage → fast-forward phía ahead.
- Sửa độc lập hai phía → conflict, **không** silent overwrite.

SEO-OPS không gửi lệnh điều khiển browser hay yêu cầu chỉnh sửa kịch bản tự động về RPA Admin.

### Luồng 2: SEO-OPS ↔ RPA Client

**Hai chiều, hoạt động thường xuyên** (khi Runner sẵn sàng).

Bao gồm:

- Register/enrollment.
- Heartbeat.
- Session/account synchronization (Runner only).
- Job claim/ack/progress.
- Result.
- Error.
- Screenshot/artifacts.

Đây là kết nối vận hành chính của toàn hệ thống khi Client đã được xây.

---

## 3. Quy trình hoàn chỉnh

1. Developer tạo Script trong RPA Admin.
2. Kiểm thử bằng Test Lab.
3. Quay video demo kịch bản.
4. Đồng bộ Script/version/assets/video với SEO-OPS Script Registry (hai chiều; conflict cần xử lý tay).
5. SEO-OPS lưu release bất biến trong Script Registry.
6. Quản trị viên tạo Widget từ Variables của Script.
7. Có thể kết hợp nhiều Widget thành Workflow.
8. SEO-OPS chuẩn bị input bằng Manual Mode hoặc AI Auto Mode.
9. Task/Scheduler chọn thời gian, máy và account phù hợp.
10. SEO-OPS phân phối Job cho RPA Client.
11. Client chạy Script hoặc chuỗi Script.
12. Client gửi kết quả và screenshot.
13. SEO-OPS lưu lịch sử, báo cáo và artifacts.

Workflow là tùy chọn. Một Widget cũng có thể được dùng trực tiếp để tạo Task.

---

## 4. Định nghĩa dữ liệu

### Script

Script là kịch bản kỹ thuật được tạo bởi RPA Admin.

Chứa:

- Stable Script ID.
- Version.
- Steps/Actions.
- Variables.
- Input/output schema.
- Runtime dependencies.
- ROI/assets.
- Content digest.
- Video demo reference.

Script release là bất biến.

Khi Facebook/X thay đổi giao diện, developer sửa Script trong Admin và phát hành version mới.

Task đã pin version cũ không tự động chuyển sang version mới.

### Widget

**Widget là phiên bản End User của một Script.**

Widget không phải execution engine riêng.

Widget là lớp Form Builder và validation đặt phía trên Variables của Script.

Các trường có thể bao gồm:

- Field label.
- Input type.
- Required/Optional.
- Default value.
- Validation rules.
- Help text.
- Visibility.
- Script variable binding.
- Data source configuration.

Một Script có thể tạo nhiều Widget với cấu hình input khác nhau.

Không nhân bản Steps vào Widget.

### Workflow

Workflow là tập hợp Widget chạy tuần tự.

Ví dụ:

- Step 1: Widget đăng bài Facebook Group.
- Step 2: Widget bình luận vào bài vừa đăng.

Workflow quản lý:

- Step order.
- Widget version references.
- Input/output mappings.
- Error behavior.
- Variables và dữ liệu truyền giữa các Steps.

Không chuyển account giữa các Steps của cùng execution.

### Task và Job

Task là yêu cầu nghiệp vụ cần thực hiện bằng Widget hoặc Workflow.

Job là một lượt thực thi được phân phối cho account/session đủ điều kiện.

SEO-OPS chịu trách nhiệm điều phối. RPA Client chịu trách nhiệm chạy automation.

---

## 5. Manual Mode và AI Auto Mode

Hai chế độ được quản lý hoàn toàn trong SEO-OPS.

### Manual Mode

End User nhập dữ liệu qua Widget Form.

### AI Auto Mode

SEO-OPS dùng AI để tạo dữ liệu theo cấu trúc Widget đã định nghĩa.

Mỗi variable có thể lấy từ:

- Manual input.
- AI generation.
- Static configuration.
- Data source.
- Output của Workflow Step trước.

Không cho AI tự suy đoán các giá trị bắt buộc phải chính xác, chẳng hạn account identity hoặc URL group thực tế.

Cả Manual và Auto đều phải tạo ra cùng một validated input payload.

RPA Client không cần biết dữ liệu đến từ AI hay người dùng.

---

## 6. Workflow Builder — Nguyên tắc tái sử dụng

RPA Admin đã có Workflow Builder.

**Không xây lại toàn bộ Workflow Builder từ đầu.**

Cần tận dụng:

- Data schema.
- Workflow composition.
- Step order.
- Input/output mapping.
- Version pinning.
- Validation.
- UI/canvas nếu phù hợp.

Khi chuyển sang SEO-OPS, thay đối tượng tham chiếu từ Scenario/Variation sang Widget.

RPA Admin dùng React/TypeScript, SEO-OPS dùng Laravel/Filament. Việc nhúng React Builder hay chỉ tái sử dụng logic/schema cần kiểm tra source trước khi triển khai.

Không đưa browser execution runtime từ RPA Admin vào SEO-OPS.

---

## 7. Video demo — Yêu cầu bắt buộc của quy trình

RPA Admin cần bổ sung tính năng quay video demo trong Test Lab.

Mục tiêu: người quản lý có thể xem Script làm gì mà không cần đọc toàn bộ Steps.

Yêu cầu:

- Quay quá trình thực thi trong browser.
- Gắn video với Script ID/version và lần test.
- Cho phép xem lại trước khi phát hành.
- Lưu video/artifact metadata.
- Phát hành cùng Script release hoặc reference hợp lệ.
- SEO-OPS có thể hiển thị video tại trang chi tiết Script.

Không cần quay toàn bộ desktop hoặc thu âm.

Video có thể chứa nội dung nhạy cảm đang hiển thị nên phải có kiểm soát trước khi upload/phát hành.

Video demo khác với screenshot kết quả Job.

Draft có thể chưa có video. Release chính thức dự kiến cần video hợp lệ; cơ chế enforcement sẽ được xác nhận khi triển khai.

Video giúp hiểu chức năng Script, không chứng minh mọi máy và mọi tài khoản đều chạy thành công.

---

## 8. Những chức năng chuyển khỏi RPA Admin

Các module hiện có:

- Variations.
- Widget Registry.
- Workflows.
- Builds & Releases.

Chuyển quyền quản lý nghiệp vụ sang SEO-OPS.

**Chỉ bảo toàn dữ liệu, fields, constraints và behavior cần thiết. Không cần giữ cách trình bày UI hiện tại.**

Trước khi chuyển phải audit schema thực tế.

Không xóa ngay module/DB cũ nếu chưa xác minh dữ liệu và phần tái sử dụng.

RPA Admin sau cùng chỉ tập trung vào Script Creator, Variables, Test Lab, video demo và Script Publish.

---

## 9. Browser và Account

Domain tag như `facebook.com` chỉ xác định nền tảng, không xác định account.

Account identity cần liên kết với:

- Platform/domain.
- Verified platform account identifier.
- Browser session.
- Agent.

Không mặc định email luôn tồn tại hoặc là định danh duy nhất.

Account chưa đủ identity không được phân phối.

Các Job phải tôn trọng:

- Account locks.
- Session locks.
- Agent concurrency.
- Lease/attempt fencing.
- Idempotency.

Một Workflow không chuyển account giữa các Steps.

---

## 10. Offline và Failure Recovery

Chính sách đã chốt:

- Máy offline: skip lượt.
- Không tự catch-up các lượt bỏ qua.
- Thiếu account: partial dispatch theo chính sách hiện có.
- Không tự đăng lại khi trạng thái kết quả chưa chắc chắn.

Ví dụ: RPA Client đăng bài thành công nhưng mất mạng trước khi gửi result.

SEO-OPS không được mặc định tạo Job đăng lại vì có nguy cơ tạo bài trùng.

Late/stale attempts không được ghi đè kết quả mới.

---

## 11. Service và Database

Social Automation là Service độc lập ngang hàng SEO và Seeding.

- Addon: `social-automation/`.
- Namespace: `Omnichannel\Addons\SocialAutomation`.
- Database: `omi_social_automation`.
- Table prefix: `sa_`.
- Không phụ thuộc `site_id`.
- Entitlement riêng từ OPS-SERVER.

Không kế thừa cơ chế miễn license của Agent.

Production hiện được khóa bằng Deployment Allowlist.

Không mở khóa, migrate hoặc test E2E trên production khi chưa có kế hoạch kiểm thử cuối.

---

## 12. Trạng thái phát triển tại thời điểm chốt

Theo báo cáo coding agent:

**SEO-OPS Social Automation**

- Phase A–E và 1–11 đã CODE COMPLETE.
- Unit tests: 68 PASS / 267 assertions.
- Commit checkpoint: `dd8a6de90a8b52b4ed10e15c23a2c0b6f0473bdf`.
- Integration/E2E chưa kiểm chứng.
- Script Registry theo kiến trúc mới còn cần bổ sung.

**RPA Admin v2**

- Có Script/Steps/Variables/Test Lab.
- Build/typecheck/unit tests đã PASS theo báo cáo.
- Script Export hiện là provisional.
- Chưa có video demo chính thức.
- Chưa publish Script thành công vào Social Automation.

**RPA Client**

- Chưa xây dựng.

Các trạng thái này là kết quả báo cáo, không thay thế source review hoặc E2E.

---

## 13. Thứ tự triển khai tiếp theo

### Phase 1 — RPA Admin

- Chốt Script export schema.
- Bổ sung video demo Test Lab.
- Export Script/version/assets/video.
- Unit và contract tests.

### Phase 2 — SEO-OPS Script Registry

- Nhận Script release.
- Validate version/digest/dependencies.
- Lưu asset/video references.
- Bảo đảm version bất biến.

### Phase 3 — Widget Builder

- Chuyển logic Variation/Form Builder.
- Bind variables của Script.
- Validation.
- Manual/AI input schema.

### Phase 4 — Workflow Builder

- Tái sử dụng cấu trúc/logic Workflow hiện có.
- Thay đối tượng bằng Widget.
- Version pinning.
- Output-to-input mapping.

### Phase 5 — RPA Client

- Agent registration.
- Browser session/account sync.
- Job claim/ack.
- Script execution.
- Progress/result/artifacts.
- Offline/reconnect behavior.

### Phase 6 — Final Integration

- Publish Script.
- Tạo Widget.
- Tạo Workflow.
- Manual/AI input.
- Register Runner.
- Sync verified account.
- Dispatch/claim/result.
- Video và screenshot.
- MySQL concurrency tests.
- Controlled unlock.

Chủ dự án chỉ thực hiện integration/E2E một lượt cuối, không test từng phase trong quá trình phát triển.

---

## 14. Quy tắc cho coding agents

1. Đọc source hiện tại trước khi code.
2. Không tự thiết kế lại các quyết định trong tài liệu này.
3. Không xây hai Workflow Builders hoặc hai execution engines.
4. Không chuyển Widget/Workflow/AI business logic sang RPA Client.
5. Không ép Script thành Widget chỉ để khớp schema cũ.
6. Không động H1/SEO/Seeding/OPS-SERVER khi chưa được phép.
7. Không SSH từ Cursor.
8. Không mở Production Allowlist.
9. Không migrate/deploy/test E2E production.
10. Giữ nguyên thay đổi của agent khác khi Git.
11. Phân biệt CODE COMPLETE, UNIT VERIFIED, INTEGRATION PENDING và E2E NOT TESTED.
12. Nếu có khác biệt giữa tài liệu và source, báo cáo chính xác incompatibility trước khi thay đổi contract.

**Nguyên tắc cốt lõi: RPA Admin tạo Script; SEO-OPS biến Script thành chức năng End User và phân phối công việc; RPA Client chỉ thực thi và báo kết quả.**