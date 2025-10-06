<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom" dir="ltr">
    <div class="container-fluid">
        <!-- بخش کاربر در سمت چپ -->
        <div class="user-section">
            <!-- دکمه ورود/ثبت نام -->
            <a class="btn btn-outline-primary btn" href="{{route('patient.login')}}">ورود | ثبت نام</a>

            <!-- آیکون کاربر با منوی کشویی -->
            <div class="dropdown">
                <a class="dropdown-toggle user-dropdown rounded" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="bx bx-user" style="font-size:30px;"></i>
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="#">پروفایل</a>
                    <a class="dropdown-item" href="#">تنظیمات</a>
                    <a class="dropdown-item" href="#">سوابق</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="#">خروج</a>
                </div>
            </div>
        </div>

        <!-- لوگو در سمت راست -->
        <a class="navbar-brand text-primary fw-bold" href="#">!AiDoctor</a>
    </div>
</nav>
