<?php
$isAdmin = ($_SESSION['user_id'] == 1);
$mode = $_GET['mode'] ?? 'view';
$page = basename($_SERVER['PHP_SELF']);
$currentTab = $_GET['tab'] ?? 'alle';
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
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="<?php echo ($mode === 'view' && $currentTab === 'alle') ? ('categories.php?tab=' . urlencode($currentTab)) : ('index.php?mode=' . ($mode === 'view' ? 'edit' : 'view') . '&tab=' . urlencode($currentTab)); ?>">
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
            <?php elseif ($page === 'categories.php' || $page === 'notes_manage.php' || $page === 'tabs.php'): ?>
                <li class="nav-item">
                    <a class="nav-link btn btn-secondary bottom-nav-btn" href="index.php?tab=<?php echo urlencode($_GET['tab'] ?? 'alle'); ?>">
                        <span class="d-none d-sm-inline">Back</span>
                        <span class="d-sm-none">↩️</span>
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

            <li class="nav-item">
                <a
                    class="nav-link btn btn-secondary bottom-nav-btn bottom-nav-icon-btn"
                    href="https://buymeacoffee.com/yogi7777"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="https://buymeacoffee.com/yogi7777"
                    aria-label="Buy Me a Coffee"
                >☕</a>
            </li>

            <li class="nav-item bottom-zoom-item">
                <div class="bottom-zoom-control" title="UI Zoom (nur auf diesem Gerät)">
                    <div class="bottom-zoom-label-row">
                        <label for="uiZoomRange" class="bottom-zoom-label">Zoom</label>
                        <span id="uiZoomValue" class="bottom-zoom-value">100%</span>
                    </div>
                    <div class="bottom-zoom-row">
                        <input
                            type="range"
                            id="uiZoomRange"
                            class="form-range"
                            min="70"
                            max="150"
                            step="5"
                            value="100"
                            aria-label="UI Zoom"
                        >
                        <button type="button" id="uiZoomReset" class="btn btn-sm btn-outline-light">Reset</button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</nav>
