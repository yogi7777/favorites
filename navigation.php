<?php
$is_admin = ($_SESSION['user_id'] == 1);
$mode = $_GET['mode'] ?? 'view';
$page = basename($_SERVER['PHP_SELF']);
$title = ($page === 'index.php') ? 'Favorites' : ($page === 'profile.php' ? 'Profile' : 'Admin Panel');
?>
<nav class="navbar fixed-top navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <!-- Logo links - nur auf Desktop -->
        <a class="navbar-brand d-none d-lg-block" href="<?php echo $page === 'index.php' ? 'index.php' : 'index.php'; ?>"><?php echo $title; ?></a>
        
        <!-- Hamburger-Menü für mobile Ansicht - links auf Mobilgeräten -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation links auf Desktop, ausklappbar auf Mobilgeräten -->
        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <?php if ($page === 'index.php'): ?>

                    <?php if ($is_admin): ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-secondary mx-1 my-1" href="admin.php">Admin</a>
                        </li>

                    <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="?mode=<?php echo $mode === 'view' ? 'edit' : 'view'; ?>">
                            <?php echo $mode === 'view' ? 'Edit' : 'Back'; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="profile.php">Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="logout.php">Logout</a>
                    </li>

                <?php elseif ($page === 'admin.php' || $page === 'clean_png.php'): ?>

                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="index.php">Back</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="admin.php">Admin</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="clean_png.php">CleanPNG</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="logout.php">Logout</a>
                    </li>

                <?php elseif ($page === 'profile.php'): ?>

                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="index.php">Back</a>
                    </li>
                    <?php if ($is_admin): ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-secondary mx-1 my-1" href="admin.php">Admin</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary mx-1 my-1" href="logout.php">Logout</a>
                    </li>

                <?php endif; ?>

            </ul>
        </div>
        
        <!-- Suche immer rechts -->
        <form class="d-flex ms-auto" id="searchForm">
            <input class="form-control me-2" type="search" id="search" placeholder="🔍 <?php echo $title; ?>" aria-label="Search">
        </form>
    </div>
</nav>