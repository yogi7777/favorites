<?php
$isAdmin = ($_SESSION['user_id'] == 1);
$mode = $_GET['mode'] ?? 'view';
$page = basename($_SERVER['PHP_SELF']);
?>
<nav class="bottom-nav navbar navbar-expand navbar-dark">
    <div class="container-fluid justify-content-center">
        <ul class="navbar-nav bottom-nav-list">
            <?php if ($page === 'index.php'): ?>
                <?php if ($isAdmin): ?>
                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary bottom-nav-btn" href="admin.php">
                            <span class="d-none d-sm-inline">Admin</span>
                            <span class="d-sm-none">🛠️</span>
                        </a>
                    </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="index.php?mode=<?php echo $mode === 'view' ? 'edit' : 'view'; ?>&tab=<?php echo urlencode($_GET['tab'] ?? 'alle'); ?>">
                        <span class="d-none d-sm-inline"><?php echo $mode === 'view' ? 'Edit' : 'Back'; ?></span>
                        <span class="d-sm-none"><?php echo $mode === 'view' ? '✏️' : '↩️'; ?></span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="profile.php">
                        <span class="d-none d-sm-inline">Profile</span>
                        <span class="d-sm-none">👤</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="logout.php">
                        <span class="d-none d-sm-inline">Logout</span>
                        <span class="d-sm-none">🚪</span>
                    </a>
                </li>
            <?php elseif ($page === 'admin.php' || $page === 'clean_png.php'): ?>
                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="index.php">
                        <span class="d-none d-sm-inline">Back</span>
                        <span class="d-sm-none">↩️</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="admin.php">
                        <span class="d-none d-sm-inline">Admin</span>
                        <span class="d-sm-none">🛠️</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="clean_png.php">
                        <span class="d-none d-sm-inline">CleanPNG</span>
                        <span class="d-sm-none">🧹</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="logout.php">
                        <span class="d-none d-sm-inline">Logout</span>
                        <span class="d-sm-none">🚪</span>
                    </a>
                </li>
            <?php elseif ($page === 'profile.php'): ?>
                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="index.php">
                        <span class="d-none d-sm-inline">Back</span>
                        <span class="d-sm-none">↩️</span>
                    </a>
                </li>

                <?php if ($isAdmin): ?>
                    <li class="nav-item">
                        <a class="nav-link btn btn-secondary bottom-nav-btn" href="admin.php">
                            <span class="d-none d-sm-inline">Admin</span>
                            <span class="d-sm-none">🛠️</span>
                        </a>
                    </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="logout.php">
                        <span class="d-none d-sm-inline">Logout</span>
                        <span class="d-sm-none">🚪</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>
