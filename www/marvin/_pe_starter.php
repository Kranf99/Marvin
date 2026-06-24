<button class="mobile-menu-toggle" onclick="toggleMobileMenu()">☰</button><div class="sidebar-overlay" onclick="toggleMobileMenu()"></div><div class="container"><div class="sidebar">
<div class="logo">
    <a href="/marvin/home.php">
    <img src="ressources/marvin.svg" height="50px"/>
    <span class="logotext">Marvin</span>
    </a>
</div>
<a href="report.php" class="nav-item" style="color:#fff; text-decoration:none;">
    <img src="ressources/reports.svg" height="25px"/> Reports</a>
<a href="storage.php" class="nav-item" style="color:#fff; text-decoration:none;">
    <img src="ressources/database.svg" height="25px"/> Storage</a> 
<a href="workflow.php" class="nav-item" style="color:#fff; text-decoration:none;">
    <img src="ressources/anatella.svg" height="25px"/> Workflows</a>
<a href="glossary.php" class="nav-item" style="color:#fff; text-decoration:none;">
    <img src="ressources/glossary.svg" width="28px"/> Glossary</a>
<a href="lineage/lineage.php" class="nav-item" style="color:#fff; text-decoration:none;">
    <img src="ressources/lineage.svg" height="28px"/> Lineage</a>
<a href="Task.php" class="nav-item" style="color:#fff; text-decoration:none;">
    <img src="ressources/tasks.svg" height="23px"/> Tasks</a>
<a href="user.php" class="nav-item" style="color:#fff; text-decoration:none;">
    <img src="ressources/users.svg" height="23px"/> People</a>
</div>

<!-- Main content -->
<div class="main-content">
<!-- Header -->
<div class="header">
    <div class="search-bar">
        <input type="text" placeholder="Search">
    </div>
    <div class="header-actions">
        <button class="btn">↗️ SHARE</button>
        <span>❓</span>
        <span>🔔</span>
        <div class="user-profile-wrapper">
            <input type="checkbox" id="menu-toggle">
            
            <label for="menu-toggle" class="user-profile">
<?php
echo '<img src="'.$resultUser['imageFile'].'" class="avatar" alt="Avatar"/>'.
     '<span>'.$resultUser['name'].'</span>';
 ?>
                <span>▼</span>
            </label>

            <div class="dropdown-menu">
                <div class="menu-header">
<?php
echo '<img src="'.$resultUser['imageFile'].'" class="avatar-large" alt="Avatar"/>'.
     '<div class="menu-header-info"><h3>'.$resultUser['name'].'</h3><p>'.$resultUser['email'].'</p></div>';
?>
                </div>

                <div class="menu-section">
                    <a href="profile.php" class="menu-item">
                        <span class="icon">👤</span>
                        <span>My Profile</span>
                    </a>
            <!--    <a href="#settings" class="menu-item">
                        <span class="icon">⚙️</span>
                        <span>Paramètres du Compte</span>
                    </a>-->
                    <a href="#theme" class="menu-item">
                        <span class="icon">🎨</span>
                        <span>Personnalized Theme</span>
                    </a>
              <!--  <a href="#privacy" class="menu-item">
                        <span class="icon">🔒</span>
                        <span>Confidentialité</span>
                    </a>-->
                </div>

                <div class="menu-divider"></div>

                <div class="menu-section">
                    <a href="#help" class="menu-item">
                        <span class="icon">💡</span>
                        <span>Help &amp; Support</span>
                    </a>
<!--                <a href="#dashboard" class="menu-item">
                        <span class="icon">📊</span>
                        <span>Tableau de Bord</span>
                    </a>-->
                </div>

                <div class="menu-footer">
                    <a href="logout.php">🚪 Disconnect</a>
                </div>
            </div>

            <label for="menu-toggle" class="menu-overlay"></label>
        </div>
    </div>
</div>
<script>
//////////////////////////////////
//       Toggle Mobile Menu     //
//////////////////////////////////

function toggleMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const menuToggle = document.querySelector('.mobile-menu-toggle');
    
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
    menuToggle.classList.toggle('hidden');
}
</script>
<?php
function getIcon($at)
{
    if ($at<100) return '📈';
    if ($at<120) return '<img src="ressources/file.svg" height="40px" style="vertical-align: middle;"/>';
    if ($at<200) return '<img src="ressources/database.svg" height="40px" style="vertical-align: middle;"/>';
    if ($at<300) return '<img src="ressources/anatella.svg" height="40px" style="vertical-align: middle;"/>';
    if ($at<400) 
    {
        if ($at<320) return '📋';
        if ($at<340) return '🔴';
        if ($at<360) return '🟡';
        if ($at<380) return '🟢';
        return '📐'; // 📌';
    }
    if ($at<500) return '📖';
    if ($at<600) return '👥';
    return '?';
}

function getHumanElapsedTime($timestamp)
{
    $mydate = DateTime::createFromFormat('Ymd H:i:s', $timestamp);
     if (!$mydate) {
        return "Invalid date";
    }
    $nowdate=new DateTime();
    $interval = $nowdate->diff($mydate);
    // Years
    if ($interval->y > 0) {
        return $interval->y == 1 ? "1 year ago" : "{$interval->y} years ago";
    }
    // Months
    if ($interval->m > 0) {
        return $interval->m == 1 ? "1 month ago" : "{$interval->m} months ago";
    }
    // Days
    if ($interval->d > 0) {
        if ($interval->d == 1) return "yesterday";
        if ($interval->d < 7) return "{$interval->d} days ago";
        $weeks = floor($interval->d / 7);
        return $weeks == 1 ? "1 week ago" : "{$weeks} weeks ago";
    }
    // Hours
    if ($interval->h > 0) {
        return $interval->h == 1 ? "1 hour ago" : "{$interval->h} hours ago";
    }
    // Minutes
    if ($interval->i > 0) {
        return $interval->i == 1 ? "1 minute ago" : "{$interval->i} minutes ago";
    }
    // Seconds
    return "just now";
}

function defaultAvatarImage($ai)
{
    if ($ai!="") return $ai;
    return "ressources/defaultavatar.svg";
}

function getStatusDisplay($status)
{
    if ($status=="0") return "<div style='width:20px; height:20px'></div>";
    if ($status=="2") return "<img src=\"ressources/forbidden.svg\" height=\"20px\">";
    return "<img src=\"ressources/certified.svg\" height=\"20px\">";
}
?>