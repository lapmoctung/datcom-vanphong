# Đặt Cơm IDEA — bản chạy trên Hostinger

Web tĩnh (HTML/JS) + API PHP + MySQL. Mọi người dùng mở cùng một địa chỉ, dữ liệu lưu ở MySQL.

```
public_html/
  index.html          giao diện (mobile)
  db-client.js        lớp kết nối API (tự cập nhật mỗi 8 giây)
  .htaccess
  api/
    index.php         API
    config.sample.php mẫu cấu hình  → copy thành config.php
```

## 1. Tạo database (hPanel)
1. hPanel → **Databases → MySQL Databases** → tạo database + user + mật khẩu (ghi lại 3 thông tin này).
2. Không cần tạo bảng: API tự tạo bảng `docs` và `attempts` ở lần gọi đầu tiên.

## 2. Đưa code lên GitHub
```
git init && git add . && git commit -m "Đặt Cơm IDEA"
git branch -M main
git remote add origin https://github.com/<tên>/<repo>.git
git push -u origin main
```
Chọn repo **Private**. File `config.php` đã nằm trong `.gitignore`, sẽ không bị đẩy lên.

## 3. Đưa lên Hostinger (chọn 1 cách)
**A. Upload tay:** hPanel → File Manager → vào thư mục site (`public_html` hoặc thư mục subdomain) → upload toàn bộ **nội dung** thư mục `public_html/` của gói này (index.html, db-client.js, .htaccess, api/).

**B. Tự cập nhật từ GitHub:** hPanel → Websites → Manage → Advanced → **Git** → dán link repo, nhánh `main`, thư mục cài đặt là thư mục site → Create. Repo private thì thêm SSH key Hostinger cung cấp vào GitHub (Settings → Deploy keys). Bật Auto Deployment để push là tự cập nhật. Vì repo có thư mục `public_html/` bên trong, hãy chắc rằng web root trỏ đúng vào thư mục chứa `index.html`.

## 4. Tạo cấu hình (làm tay trên Hostinger, không qua GitHub)
Trong File Manager, vào `api/`, copy `config.sample.php` → `config.php`, sửa:
`dbname`, `user`, `pass` (MySQL ở bước 1) và `secret` (chuỗi ngẫu nhiên ≥ 32 ký tự).
Bật SSL (hPanel → Security → SSL) để web chạy bằng https.

## 5. Dùng lần đầu
Mở web → nhập tên + SĐT **của admin** → app hỏi tạo PIN (4–8 số). Người đăng nhập đầu tiên chính là admin, nên hãy là bạn làm bước này trước khi gửi link cho mọi người.

## Bảo mật đã có
- Mã PIN admin không bao giờ gửi về trình duyệt; đăng nhập admin qua server, khóa 10 phút nếu sai 10 lần.
- Người dùng thường chỉ tạo/hủy đơn của mình; món, thực đơn, hóa đơn, cài đặt chỉ admin sửa được.
- Giờ chốt được kiểm tra trên server (không tin đồng hồ máy người dùng).
- Người dùng vẫn đăng nhập bằng tên + SĐT (không mật khẩu) — phù hợp nội bộ văn phòng.

## Sao lưu
Bật backup hằng ngày của Hostinger, hoặc hPanel → phpMyAdmin → Export bảng `docs` định kỳ (đây là toàn bộ dữ liệu, gồm công nợ).

## Chạy thử trên máy
`php -S 127.0.0.1:8080 -t public_html` với `api/config.php` dùng `'driver'=>'sqlite'` (xem config.sample.php).
