# Telegram Channel Mirror

Tool PHP tự đăng lại bài mới từ `@honghotduongpho0` sang `@hhdp3105` và `@honghotduongpho2027` bằng tài khoản Telegram đăng nhập qua MadelineProto. Tài khoản này vừa đọc nguồn, vừa đăng ở đích; không cần BotFather hoặc bot token.

Tool dùng `sendMessage`, `sendMedia` và `sendMultiMedia` để tạo bài mới ở đích, không có nhãn chuyển tiếp hoặc link nguồn tự thêm. Giữ nguyên chữ, entities, chú thích và primary media; ảnh/video dùng lại tham chiếu có sẵn trên Telegram, không tải xuống rồi tải lên hosting. Album được gom trong khoảng `album_settle_seconds`; phần tới muộn có thể thành bài riêng. [Gửi lại media có sẵn](https://core.telegram.org/api/files#resending-existing-files)

Payload và thành viên lượt gửi được lưu riêng tư trước khi gửi; retry dùng cùng nội dung và random ID. Lưu ID bài đích sau ACK đầy đủ để đối soát. Hàng cũ đã chuyển tiếp giữ nguyên; pending cũ được cách ly khi chuyển chế độ và không đăng lại. Preview của link có thể được Telegram tạo lại; chưa cam kết giữ các rendition video phụ, poll hoặc live photo.

## Phạm vi

- Nhiều channel nguồn, mỗi nguồn chọn một hoặc nhiều channel đích. Hàng đợi và mốc bài của từng nguồn được lưu riêng; cùng số ID bài hoặc ID album ở hai nguồn không bị trộn. Mỗi đích có random ID, thử lại và tạm dừng riêng.
- Theo dõi bài mới bằng worker chạy liên tục; hàng đợi SQLite lưu trạng thái để hạn chế đăng trùng khi khởi động lại.
- Thời gian cập nhật phụ thuộc kết nối, thời gian gom album, hàng đợi và giới hạn Telegram; không bảo đảm đồng bộ tức thì.
- Chỉ nhận bài mới sau lần worker kích hoạt thành công đầu tiên, không tự nhập lịch sử. Thêm đích mới lưu mốc bài mới nhất tại lúc kích hoạt riêng cho đích đó; không chuyển backlog cũ sang đích mới. Khởi động lại giữ các mốc và random ID đã lưu.
- Chưa đồng bộ bài sửa/xóa, bình luận, reaction, lượt xem hoặc bài ghim.
- Bài/channels chặn chuyển tiếp được bỏ qua và ghi log. Telegram trả lỗi `CHAT_FORWARDS_RESTRICTED` cho nội dung được bảo vệ. [Bảo vệ nội dung](https://core.telegram.org/api/content-protection)

## Hosting cần đáp ứng

CPanel là bảng quản lý hosting; có cPanel không đồng nghĩa gói hosting chạy được worker này. Dự án yêu cầu PHP 8.3 trở lên. Kiểm tra PHP **CLI** của Terminal/SSH, vì PHP chạy website có thể là phiên bản khác.

| Điều kiện | Yêu cầu |
| --- | --- |
| Hệ điều hành/PHP | Linux hoặc Unix, PHP CLI 8.3 trở lên, 64 bit; PHP 8.4 được thư viện khuyến nghị |
| Extension thư viện | `mbstring`, `xml`, `json`, `fileinfo`, `gmp`, `openssl`, `iconv`, `gd` |
| Extension dependency/kiểm tra | `dom`, `filter`, `hash`, `zlib`, `tokenizer` |
| Extension hàng đợi | `PDO`, `pdo_sqlite` |
| Công cụ cài đặt | Composer; Terminal hoặc SSH để đăng nhập tương tác lần đầu |
| Hàm PHP CLI | `stream_socket_client`, `stream_socket_server`, `proc_open`, `flock` không bị chặn |
| Tiến trình | Cho phép PHP CLI chạy lâu, không bị đóng theo giới hạn tiến trình của gói hosting |
| Mạng/lưu trữ | Kết nối ra máy chủ Telegram; thư mục `storage/` ghi được và có dung lượng cho session/log |

`ffi` và `uv` giúp thư viện chạy hiệu quả hơn nhưng không thay thế các điều kiện trên. Thư viện khuyến nghị CLI cho ứng dụng theo dõi sự kiện chạy lâu. [Yêu cầu MadelineProto](https://docs.madelineproto.xyz/docs/REQUIREMENTS.html)

Nếu gói hosting chỉ chạy PHP qua trình duyệt, hoặc chủ hosting cấm worker chạy liên tục, bản này cần chuyển sang VPS hoặc gói hỗ trợ background worker. Cron watchdog chỉ khởi động lại worker; nó không thay đổi chính sách hoặc giới hạn của hosting.

## Quản lý nguồn và đích trên website

Vào `/admin.php`, đăng nhập tài khoản quản trị website để thêm, sửa, xóa hoặc tạm dừng một cấu hình nguồn → các đích. Tài khoản này quản lý tool; phiên Telegram đang lưu trên hosting tiếp tục được dùng để đọc và đăng bài. Không nhập mật khẩu Telegram ở trang quản trị.

Lần đầu, chạy `php bin/admin-setup.php` trong Terminal riêng của ứng dụng để tạo link thiết lập dùng một lần. Mở link đó và tự đặt tên đăng nhập, mật khẩu quản trị. Link hết hạn sau 15 phút; có thể tạo link mới trước khi tài khoản quản trị được thiết lập. Không chia sẻ link thiết lập.

Website không mở đăng ký công khai. Truy cập trực tiếp `/admin.php` chỉ hiện form đăng nhập; form thiết lập chỉ xuất hiện trong phiên đã mở link riêng hợp lệ. Yêu cầu tạo tài khoản thiếu quyền thiết lập bị chặn, kể cả khi có mã CSRF hợp lệ. Chỉ có một tài khoản quản trị; tạo thành công sẽ vô hiệu hóa link và quyền thiết lập của mọi phiên. Trước khi tài khoản được tạo, bất kỳ ai có link riêng còn hạn đều có quyền thiết lập, nên giữ link này bí mật.

Worker đọc thay đổi cấu hình tự động. Nó kiểm tra nguồn là channel, tài khoản đã tham gia và có quyền đăng ở từng đích; lỗi của nguồn mới được hiển thị và không dừng nguồn khác đang hoạt động. Bật nguồn hoặc thêm/bật lại đích bắt đầu từ bài mới tại lúc kích hoạt thành công; không nhập lịch sử đã đăng trong thời gian tạm dừng. Các bài đã nằm trong hàng đợi trước khi tạm dừng được giữ nguyên để tiếp tục xử lý. Xóa cấu hình chỉ dừng tuyến đăng, giữ dữ liệu lịch sử.

Dữ liệu quản trị, mật khẩu đã băm, phiên web và `routes.json` nằm trong `storage/` riêng, ngoài thư mục công khai. Trang quản trị ghi cấu hình; worker CLI thực hiện Telegram.

## 1. Chuẩn bị tài khoản và channel

1. Dùng tài khoản Telegram mà bạn kiểm soát; tài khoản phải đọc được và đã tham gia `@honghotduongpho0`.
2. Thêm **tài khoản đăng nhập** này làm quản trị viên channel đích, bật quyền **Post Messages / Đăng tin nhắn**. Tài khoản chủ channel cũng có thể dùng.
3. Đăng nhập [my.telegram.org](https://my.telegram.org), vào **API development tools** để lấy `api_id` và `api_hash`. Đây là thông tin ứng dụng Telegram, khác bot token. [Hướng dẫn Telegram](https://core.telegram.org/api/obtaining_api_id)
4. Hai đích cấu hình là `@hhdp3105` và `@honghotduongpho2027`; tài khoản cần quyền đăng từng đích. Giữ đích cũ khi thêm đích qua `targets`. Channel riêng tư dùng ID dạng `-100...` và tài khoản phải có quyền truy cập.

## 2. Đặt mã nguồn và cấu hình

Đặt thư mục ứng dụng **ngoài `public_html`**, ví dụ `/home/CPANEL_USER/telegram`. Phần đọc/đăng Telegram chạy qua CLI. Trang công khai không có endpoint thao tác Telegram hoặc nhận bí mật.

Thư mục `public/` chứa trang cho `thucunghaiza.site`, hiển thị nguồn, hai đích và trạng thái từ báo cáo riêng `storage/deploy/live-status.json`. `ready` yêu cầu tiến trình worker đúng PID/UID/argv/khóa, heartbeat và báo cáo dưới 30 giây, không tạm dừng toàn cục. Trang chỉ hiển thị các trạng thái cho phép, không xuất JSON, session, token hoặc thông tin tài khoản. Sửa đường dẫn báo cáo trong `public/index.php` khi dùng hosting khác.

Trong **cPanel → Domains**, đặt Document Root của domain vào `/home/CPANEL_USER/telegram/public` nếu gói hosting cho phép. Nếu Document Root bắt buộc nằm trong `public_html`, chỉ chép **nội dung bên trong `public/`** (`index.php` và `.htaccess`) vào Document Root; giữ `config.php`, `bin/`, `src/`, `vendor/` và `storage/` ở thư mục riêng ngoài web. Trỏ domain tới trang giới thiệu không tự kích hoạt worker.

Trong Terminal/SSH:

```sh
cd /home/CPANEL_USER/telegram
cp config.example.php config.php
composer install --no-dev --prefer-dist --optimize-autoloader
```

Composer phải chạy bằng PHP CLI 8.3 trở lên; `composer.json` ghim `danog/madelineproto` ở bản ổn định **8.7.0** để thống nhất API đã dùng trong dự án.

`telegram_transport` chọn `https` cho MTProto qua HTTPS trên shared hosting; chọn `tcp` khi hosting cho phép kết nối Telegram trực tiếp. Nếu HTTPS bị timeout khi chờ phản hồi RPC, có thể thử `wss` qua WebSocket bảo mật; hosting cần cho phép WebSocket upgrade. Chế độ `wss` dùng Abridged với obfuscation và tắt tự chuyển về HTTPS để kết quả thử kết nối rõ ràng. Các giao thức này được MadelineProto hỗ trợ, không cần một dịch vụ proxy khác. [Thiết lập kết nối](https://docs.madelineproto.xyz/PHP/danog/MadelineProto/Settings/Connection.html)

Nếu chưa có lệnh Composer và hosting cho chạy PHP CLI qua cron, có thể dùng `deploy/install-dependencies.php` trong thư mục ứng dụng riêng. Installer tải Composer từ nguồn chính thức, xác minh SHA384, cài dependency và kiểm tra yêu cầu của dependency bằng cùng PHP CLI; giới hạn mỗi lượt khoảng sáu phút. Các tiến trình Composer được chạy với tùy chọn PHP `-d allow_url_fopen=1` riêng cho lệnh đó, không đổi cấu hình PHP của hosting. Cách xác minh theo [hướng dẫn Composer](https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md).

```sh
/path/to/php /home/CPANEL_USER/telegram/deploy/install-dependencies.php
```

Có thể đặt lệnh này vào cron mỗi phút **tạm thời** để cài một lần. Khóa ngăn chạy chồng; trạng thái thành công hoặc thất bại mặc định đều ngăn cron tự thử lại liên tục. Kiểm tra `storage/deploy/state.json` và `storage/deploy/install.log` qua công cụ quản lý file riêng của hosting: `running` là đang cài, `success` là dependency đã cài/kiểm tra xong, `failed` là cần đọc log và sửa lỗi. Xóa cron installer sau khi có kết quả.

Sau khi sửa lỗi, chạy thêm `--retry` để thử lại một trạng thái `failed`; installer đổi tên trạng thái lỗi cũ thành `state-YYYYMMDD-HHMMSS-PID.json` trước khi cài lại. Trạng thái `success` không chạy lại, kể cả khi thêm `--retry`. Nếu dùng cron tạm có `--retry`, gỡ cron khi có kết quả để tránh tiếp tục thử lại lỗi chưa được sửa. Installer không đăng nhập Telegram và không kích hoạt worker; không xóa dữ liệu phiên/hàng đợi để cài dependency.

```sh
/path/to/php /home/CPANEL_USER/telegram/deploy/install-dependencies.php --retry
```

Mở `config.php`, điền `api_id`, `api_hash`; kiểm tra nguồn `@honghotduongpho0` và danh sách `targets`. Đọc chú thích trong file cấu hình cho các tùy chọn còn lại.

Các ví dụ dưới dùng `php`. Trên hosting, thay bằng đường dẫn PHP CLI phù hợp, chẳng hạn `/opt/cpanel/ea-php83/root/usr/bin/php` **nếu đường dẫn này tồn tại trên gói của bạn**. Dùng cùng một PHP cho cài Composer, kiểm tra, đăng nhập và worker.

```sh
php -v
php bin/check.php
```

`check.php` kiểm tra điều kiện tại chỗ; kết quả đạt chưa xác nhận hosting cho phép chạy liên tục hoặc tài khoản có quyền đăng Telegram.

Để kiểm tra thêm kết nối TCP ra một máy chủ Telegram, chạy tùy chọn sau; lệnh này không đăng nhập hoặc đăng bài:

```sh
php bin/check.php --network
```

Kết nối được một địa chỉ chưa bảo đảm hosting truy cập được mọi máy chủ Telegram.

## 3. Đăng nhập lần đầu

Chạy lệnh trong Terminal/SSH tương tác:

```sh
php bin/login.php
```

Nhập số điện thoại, mã đăng nhập gửi trong Telegram và mật khẩu xác minh hai bước nếu tài khoản có bật. Thông tin này được nhập trực tiếp vào phiên CLI. Đừng đưa mã đăng nhập, mật khẩu, `config.php` hay session vào Git hoặc chia sẻ trong chat.

Nếu triển khai bằng API hosting và chưa có phiên Terminal tương tác, có thể chạy helper QR riêng bằng PHP CLI qua một Cron Job tạm thời:

```sh
php bin/login-qr.php
```

Nếu phiên IPC nền lấy được mã đầu nhưng bị timeout khi làm mới, có thể chạy `php bin/login-qr.php --full-instance` sau khi dừng phiên của ứng dụng này. Chế độ CLI này giữ đăng nhập trong một tiến trình, dùng cơ chế self-restart của đúng MadelineProto 8.7.0 đã ghim; vẫn giữ khóa session và không khởi động worker.

Helper chờ tối đa năm phút, tự làm mới mã QR khi hết hạn và lưu `storage/login-qr.svg`, `storage/login-qr.png` nếu GD hỗ trợ, cùng `storage/login-qr-state.json`. File `storage/login-qr.png.b64` là bản PNG mã hóa Base64 để lấy qua API hosting chỉ hỗ trợ đọc văn bản. Các file được ghi nguyên tử với quyền `0600`, nằm ngoài thư mục web; lấy ảnh qua API/File Manager đã xác thực của hosting rồi quét bằng Telegram → **Settings → Devices → Link Desktop Device**. Không chạy helper này lặp lại tự động; dừng worker trước và gỡ cron tạm sau khi có kết quả. Nếu cần giới hạn cả thời gian PHP kết nối ban đầu, wrapper cron phải đặt giới hạn tiến trình bên ngoài là 300 giây.

Trạng thái `waiting_qr` có `expires_at` để biết ảnh còn hiệu lực; `success` xác nhận đăng nhập và quyền channel đã kiểm tra; `needs_2fa` yêu cầu hoàn tất mật khẩu trong `bin/login.php` qua Terminal; `expired` cho biết hết lượt chờ, còn `failed` có `failure_code` hữu hạn để báo thiếu quyền, chưa tham gia nguồn hoặc sai cấu hình. Helper xóa ảnh khi kết thúc, giữ session và không kích hoạt chuyển tiếp bài viết. File trạng thái không chứa số điện thoại, tài khoản, API hash hay token. [API đăng nhập QR](https://docs.madelineproto.xyz/docs/LOGIN.html#qr-code-user)

Lệnh đăng nhập kiểm tra tài khoản đọc được channel nguồn, có quyền đăng tại channel đích và hai channel khác nhau, rồi gắn hàng đợi với tài khoản/cặp channel. Lệnh này chưa khởi động chuyển tiếp bài viết. Nếu worker đã tạm dừng do lỗi quyền truy cập, chạy lại login sau khi sửa quyền để kiểm tra và cho phép worker tiếp tục.

Phiên đăng nhập nằm trong `storage/`. Ai giữ session có thể sử dụng phiên tài khoản đó, nên chỉ tài khoản hosting của bạn được đọc thư mục này. Không upload `storage/` lên thư mục web công khai.

## 4. Chạy worker và xem trạng thái

```sh
php bin/worker.php
```

Lần kích hoạt đầu tiên, worker đọc một bài mới nhất từ nguồn để lưu mốc bắt đầu; các bài cũ và bài xuất hiện trước lần kích hoạt này sẽ không được chuyển tiếp. Worker nhận cập nhật Telegram sau đó, đưa bài mới vào hàng đợi rồi đăng lại sang đích. Khi khởi động lại, worker giữ mốc cũ và tiếp tục nhận cập nhật qua phiên Telegram đã lưu; không lấy bài mới nhất làm mốc lại.

Để worker tiếp tục chạy. Worker có khóa để một thư mục ứng dụng chỉ chạy một phiên. Không chạy một bản khác dùng cùng session và hàng đợi trên máy khác.

Nếu khởi động gặp lỗi mạng tạm thời, worker ghi thời điểm thử lại và thoát; watchdog sẽ thử lại khi hết thời gian chờ. Lỗi đăng nhập, cấu hình hoặc quyền truy cập sẽ ghi trạng thái tạm dừng; sửa nguyên nhân rồi chạy lại `bin/login.php`. Cả hai trường hợp đều giữ hàng đợi và mốc bắt đầu, không để một worker chưa sẵn sàng tiếp tục bỏ qua cập nhật.

Trong Terminal/SSH thứ hai:

```sh
php bin/status.php
```

Lệnh trạng thái đọc dữ liệu cục bộ, không đăng bài. Trong lần triển khai đầu, kiểm tra một bài mới và một album từ nguồn xuất hiện đúng ở channel đích trước khi để chạy lâu dài. Bản này chưa được kiểm chứng bằng tài khoản Telegram của bạn khi chưa đăng nhập và cấu hình channel đích.

`receiving_ready` xác nhận worker đang nhận cập nhật; `ready` cần ít nhất một đích có thể đăng, `all_targets_ready` cần tất cả đích. Xem `targets` để kiểm tra hàng đợi và pause riêng từng đích. PID/UID/argv/khóa thật và heartbeat còn mới đều bắt buộc; xem thêm `paused_reason`, `startup_error` và `startup_retry_at` nếu khởi động không thành công. Các kiểm tra tự động trong `tests/` dùng dữ liệu giả, không thay thế việc xác nhận đăng bài thực tế.

## 5. Cron watchdog trên cPanel

Sau khi đã đăng nhập và chạy thử thành công, mở **cPanel → Cron Jobs**, chọn chạy mỗi phút. Thay `CPANEL_USER` và `/path/to/php` bằng giá trị thật:

```cron
* * * * * /path/to/php /home/CPANEL_USER/telegram/bin/worker.php >> /home/CPANEL_USER/telegram/storage/worker.log 2>&1
```

Nếu cPanel có các ô riêng cho lịch, điền `*` ở năm ô và chỉ dán phần lệnh từ `/path/to/php` vào ô **Command**. Worker đang chạy sẽ giữ khóa; lần cron tiếp theo thoát khi thấy khóa, tránh mở thêm phiên Telegram. Nếu worker dừng, cron có thể khởi động lại ở phút tiếp theo.

Kiểm tra `storage/worker.log` và `php bin/status.php` sau khi bật cron. Nếu log báo lỗi liên tục, tắt cron, sửa lỗi trong Terminal rồi bật lại. Log tăng theo thời gian, cần theo dõi dung lượng và xoay log theo công cụ của hosting.

Để dừng: tắt Cron Job trước, sau đó dừng tiến trình worker trong Terminal/SSH hoặc trang quản lý tiến trình của hosting. Worker chạy foreground có thể dừng bằng `Ctrl+C`. Để đổi tùy chọn vận hành, dừng worker, sửa `config.php`, rồi khởi động lại.

Tài khoản, nguồn và các ID đích đã gắn với dữ liệu được giữ cố định. Có thể thêm đích bằng `targets`; không tự đổi/xóa đích cũ hoặc gán lại username đã đổi chủ. Migration giữ trạng thái terminal, random ID và retry của đích cũ; đích mới không nhận backlog. Nếu muốn dùng tài khoản hoặc cặp channel khác, dừng worker cũ, đổi `storage_dir` sang **thư mục riêng mới ngoài web**, điền cấu hình mới rồi đăng nhập lại. Giữ dữ liệu cũ để kiểm tra và xử lý bài còn chờ; không xóa session/hàng đợi để bỏ qua kiểm tra này.

## Xử lý lỗi thường gặp

| Hiện tượng | Cách kiểm tra |
| --- | --- |
| PHP quá cũ hoặc thiếu extension | Chạy đúng PHP CLI; bật extension tương ứng cho CLI, không chỉ PHP của website |
| Chưa có session / yêu cầu đăng nhập | Tắt cron, chạy `php bin/login.php` qua Terminal/SSH |
| Không có bài mới | Kiểm tra nguồn/đích, tài khoản đã tham gia nguồn, worker còn chạy và log gần nhất |
| Không có quyền đăng | Cấp quyền đăng bài tại channel đích cho tài khoản đang đăng nhập |
| `CHAT_FORWARDS_RESTRICTED` | Nguồn bảo vệ nội dung; bài được bỏ qua, không có bước tải lại để vượt hạn chế |
| `FLOOD_WAIT` | Telegram yêu cầu chờ; để worker xử lý thời gian chờ, không mở thêm worker |
| Worker bị hosting đóng | Hỏi nhà cung cấp về persistent PHP CLI process; chuyển sang gói hỗ trợ nếu họ không cho phép |

Giữ cả session và SQLite khi nâng cấp để duy trì đăng nhập và trạng thái hàng đợi. Dừng worker trước khi sao lưu/di chuyển dữ liệu; không xóa hàng đợi hoặc session chỉ để thử khởi động lại.

## Helper đăng nhập Telegram qua QR và xác minh hai bước

Helper này dùng để đăng nhập phiên Telegram lần đầu qua hosting API. Mở [trang loopback riêng](http://127.0.0.1:8765/) trên máy đang chạy bridge; trang này khác trang quản trị `/admin.php` và chỉ hoạt động trên máy đó. Nếu bridge đã dừng, chạy từ thư mục dự án:

```sh
python3 deploy/password-bridge.py
```

Bridge cần Python 3 và Node.js cho bước mã hóa; đường dẫn Node.js hiện là `/usr/local/bin/node` trong `deploy/password-bridge.py`. Khi dùng máy hoặc hosting khác, sửa đường dẫn này và các đường dẫn ứng dụng trong bridge, QR viewer, watchdog, audit wrapper và trang trạng thái cho phù hợp.

Bridge mở loopback trong 12 giờ. Sau khi QR được chấp nhận, người dùng tự nhập mật khẩu hai bước tại ô riêng và bấm xác minh. Không gửi mật khẩu qua chat. Cấu hình cPanel trong `host.txt` phải có ba dòng: HTTPS origin, username, API token; file giữ quyền `0600`. API từ chối redirect để không chuyển Authorization sang nơi khác.

Mật khẩu chỉ được giữ tạm trong bộ nhớ, gửi bằng envelope HKDF/AES-GCM tới thư mục riêng trên hosting, ràng buộc request ID và hạn tối đa 180 giây. Envelope được tiêu thụ một lần trước RPC, không ghi plaintext vào file/URL/log; mỗi lượt có cron riêng và timeout bên ngoài. Host/Origin/CSRF, giới hạn body và giới hạn 5 lượt/15 phút bảo vệ điểm nhập. Hủy/timeout giữ gate cho tới khi cleanup native lấy được khóa; session và queue không bị xóa. Chỉ trạng thái xác minh thành công mới bật watchdog. Nếu tài khoản đã đăng nhập thì không cần nhập lại mật khẩu.

Trên hosting này, app riêng nằm ở `/home/dwhwlekshosting/telegram-mirror-thucunghaiza`; website chỉ chứa `index.php`/`.htaccess`. PHP CLI là `/opt/cpanel/ea-php83/root/usr/bin/php`; transport đã chọn `wss`. Watchdog `deploy/watch-worker.sh` cập nhật báo cáo mỗi 5 giây và khởi động lại worker khi cần. Domain dùng bản ghi gốc, không có bản ghi `www`.

## Kiểm chứng và giới hạn

`deploy/verify.php --report-id ID` tạo báo cáo mới, kiểm exit code và marker từng suite, SHA256 trước/sau của mã triển khai, PHP và MadelineProto. Các test dùng fixture, không tạo bài lên channel. `tests/password_bridge.py` và `tests/local_security.py` kiểm HTTP/cPanel bằng dữ liệu giả; `composer audit --locked --no-dev` chỉ tra advisory hiện có, không chứng minh dependency an toàn tuyệt đối.

Cần phân biệt test fixture với bằng chứng live: đăng nhập, quyền nguồn/từng đích, worker/heartbeat, bài mới thực tế và album/restart thực tế được ghi theo từng lần kiểm tra. Không tự tạo bài test hoặc cấp admin. Nếu hosting cấm tiến trình lâu hoặc Telegram giới hạn tài khoản, cần xử lý điều kiện đó; watchdog không vượt các giới hạn này. Mã nguồn được lưu tại [thichmmo/tu_dong_dang_bai_telegram](https://github.com/thichmmo/tu_dong_dang_bai_telegram). Repository chỉ chứa mã nguồn và cấu hình mẫu; `host.txt`, `config.php`, `storage/`, ảnh đăng nhập và báo cáo triển khai riêng được loại khỏi Git.
