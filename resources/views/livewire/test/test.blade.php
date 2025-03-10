<div>
    <style>
        .navbar {
            background-color: #2196f3 !important; /* Sky Blue Navbar */
        }
        .navbar a {
            color: white !important;
            font-weight: bold;
        }
        .search-bar {
            background-color: #bbdefb;
            border: none;
            border-radius: 8px;
            padding: 8px 15px;
            width: 100%;
        }
        .card-custom {
            background-color: #64b5f6; /* Sky Blue Banner */
            border-radius: 10px;
            color: white;
        }
        .btn-custom {
            background-color: white;
            color: #2196f3;
            font-weight: bold;
        }
        .fixed-bottom-nav {
            position: fixed;
            bottom: 0;
            width: 100%;
            background-color: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
        }
        .bottom-nav-item {
            flex: 1;
            text-align: center;
            padding: 10px;
            font-size: 14px;
            color: black;
            text-decoration: none;
        }
        .active-tab {
            color: #0d47a1;
        }
        .bottom-nav-item:hover {
            background-color: #bbdefb;
        }
    </style>
    <nav class="navbar navbar-expand-lg shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">Ai Doctor</a>
        </div>
    </nav>

    <!-- Search Bar -->
    <div class="container mt-3">
        <input type="text" class="search-bar" placeholder="جست‌وجو پزشک، تخصص، بیماری">
    </div>

    <!-- Banner -->
    <div class="container mt-3">

    </div>

    <!-- Bottom Navigation (Clickable) -->
    <div class="fixed-bottom-nav d-flex border-top">
        <a href="account.html" class="bottom-nav-item">
            <i class="bi bi-person"></i>
            <div>حساب من</div>
        </a>
        <a href="messages.html" class="bottom-nav-item">
            <i class="bi bi-chat"></i>
            <div>پیام‌ها</div>
        </a>
        <a href="orders.html" class="bottom-nav-item">
            <i class="bi bi-card-list"></i>
            <div>نوبت ها</div>
        </a>
        <a href="home.html" class="bottom-nav-item active-tab">
            <i class="bi bi-house"></i>
            <div>خانه</div>
        </a>
    </div>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

</div>

