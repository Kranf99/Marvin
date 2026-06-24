<div class="specialcontent">
<div class="profile-container">
<?php
if (isset($_GET['message']))
{
    if ($_GET['message'][0] == 'O')
        echo '<div class="success-message" id="successMessage">&#10003; ' . htmlspecialchars($_GET['message']) . '</div>';
    else
        echo '<div class="error-message" id="successMessage">&#9888; ' . htmlspecialchars($_GET['message']) . '</div>';
}
?>
<div class="breadcrumb"><a href="home.php">Home</a> / <a href="user.php">Users</a> / <?php echo htmlspecialchars($targetUser['name']); ?></div>
<h1 class="page-title">Edit User</h1>

<form action="oneUserSavePhoto.php" method="POST" enctype="multipart/form-data" id="pictureupload">
  <input type="hidden" name="iduser" value="<?php echo $idUser; ?>">
  <input type="hidden" name="return" value="<?php echo $returnPage; ?>">
  <div class="profile-photo-section">
    <div class="profile-photo-preview">
      <?php echo '<img src="' . $targetUser['imageFile'] . '" class="profile-photo-large" alt="Profile Photo" id="photoPreview">'; ?>
    </div>
    <div class="photo-upload-info">
        <h3>Profile Photo</h3>
<?php 
if ($canEditPersonal)
{
?>
    <p><label for="profile_picture">Upload a new profile photo. JPG, JPEG. Max size 1MB.</label></p>
    <div class="file-input-wrapper">
        <input type="file" name="profile_picture" id="profile_picture" accept=".jpg,.jpeg,image/jpeg" required style="display:none;">
        <label for="profile_picture" class="upload-btn">
            &#128228; Upload Photo
        </label>
    </div>
<?php 
        echo '<a href="oneUserNoPhoto.php?iduser='.$idUser.'&return='.$returnPage.'" class="remove-btn">Remove Photo</a>'; 
}
?>
    </div>
  </div>
</form>

<form id="profileForm" action="oneUserSave.php" method="POST">
<input type="hidden" name="iduser" value="<?php echo $idUser; ?>">
<input type="hidden" name="return" value="<?php echo $returnPage; ?>">
<div class="form-section">
    <h3>Personal Information</h3>
    <div class="form-grid">
        <div class="form-group full-width">
            <label for="Name">Name</label>
<?php 
$dis = $canEditPersonal ? '' : ' disabled'; 
echo '<input type="text" id="Name" name="name" value="' . htmlspecialchars($targetUser['name']) . '"' . $dis . ' required>'; 
?>
        </div>
        <div class="form-group full-width">
            <label for="email">Email Address</label>
<?php 
    echo '<input type="email" id="email" name="email" value="' . htmlspecialchars($targetUser['email']) . '"' . $dis . ' required>'; 
?>
            <span class="help-text">This email will be used for notifications and login.</span>
        </div>
    </div>
</div>

<div class="form-section">
<?php if ($editPersonalPass){ ?>
    <h3>Set New Password</h3>
    <div class="form-grid">
        <div class="form-group">
            <label for="currentPassword">Current Password</label>
            <div class="password-input-wrapper">
                <input type="password" name="currentPassword" id="currentPassword" autocomplete="new-password">
                <button type="button" class="password-toggle" onclick="togglePassword('currentPassword')">&#128065;&#65039;</button>
            </div>
        </div>
        <div class="form-group"></div>
    </div>
<?php 
}
if ($isSuperAdmin||$editPersonalPass)
{
     if ($isSuperAdmin&&(!$editPersonalPass)) echo '<h3>Change Password</h3>';
?>
    <div class="form-grid">
        <div class="form-group">
            <label for="newPassword">New Password</label>
            <div class="password-input-wrapper">
                <input type="password" name="newPassword" id="newPassword" autocomplete="new-password">
                <button type="button" class="password-toggle" onclick="togglePassword('newPassword')">&#128065;&#65039;</button>
            </div>
        </div>
        <div class="form-group">
            <label for="confirmPassword">Confirm New Password</label>
            <div class="password-input-wrapper">
                <input type="password" id="confirmPassword" autocomplete="new-password">
                <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword')">&#128065;&#65039;</button>
            </div>
        </div>
    </div>
<?php } ?>
</div>

<div class="form-actions">
<!--    <a href="user.php" class="btn btn-cancel">Go Back</a>-->
    <button type="button" class="btn btn-cancel" onclick="window.history.back()">Go Back</button>
    <?php if ($isSuperAdmin||$editPersonalPass) echo '<button type="submit" class="btn btn-save">Save Changes</button>'; ?>
</div>
</form>

<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    input.type = input.type === 'password' ? 'text' : 'password';
}

document.getElementById('profile_picture').addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name || '';
    if (fileName) {
        document.getElementById('pictureupload').submit();
    }
});

document.getElementById('profileForm').addEventListener('submit', function(e) {
    const Name = document.getElementById('Name').value;
    const email = document.getElementById('email').value;
    if (Name == '') {
        alert('Please enter a name.');
        e.preventDefault();
        return;
    }
    const newPasswordEl = document.getElementById('newPassword');
    if (newPasswordEl) {
        const newPassword = newPasswordEl.value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        if ((newPassword != '') && (newPassword !== confirmPassword)) {
            alert('New passwords do not match!');
            e.preventDefault();
            return;
        }
    }
    if (email.indexOf('@') == -1) {
        alert('Invalid email address.');
        e.preventDefault();
        return;
    }
});
</script>
