<?php
// Sao chép file này thành config.php rồi điền thông tin. KHÔNG đưa config.php lên GitHub.
return [
  'driver'   => 'mysql',            // 'mysql' (Hostinger) hoặc 'sqlite' (chạy thử trên máy)
  'host'     => 'localhost',
  'dbname'   => 'u123456789_datcom',
  'user'     => 'u123456789_datcom',
  'pass'     => 'MAT_KHAU_DATABASE',
  'secret'   => 'CHUOI_NGAU_NHIEN_DAI_32_KY_TU_TRO_LEN', // ký token admin
  'allow_origin' => '',             // để trống nếu web và API cùng domain
  'timezone' => 'Asia/Ho_Chi_Minh',
];
