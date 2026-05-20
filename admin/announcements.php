<?php
session_start();
require_once '../db/dbcon.php';

// ---------------------------------------------------------------------
// SECURITY: ROLE VERIFICATION
// ---------------------------------------------------------------------
if (!isset($_SESSION['staff_id']) || $_SESSION['role'] !== 'Main Administrator') {
    header("Location: ../administrator.php");
    exit();
}

// Fetch session messages
$message = $_SESSION['msg'] ?? '';
$messageType = $_SESSION['msg_type'] ?? '';
unset($_SESSION['msg'], $_SESSION['msg_type']);

// Determine Active Tab (Default to Announcements)
$activeTab = $_GET['tab'] ?? 'Announcements';
$validTabs = ['Announcements', 'Articles', 'Rates', 'Pictures'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'Announcements';

// ---------------------------------------------------------------------
// ACTION: SAVE (ADD/EDIT), TOGGLE, DELETE
// ---------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $redirectTab = $_POST['tab_type'] ?? 'Announcements';

    // --- SAVE (INSERT OR UPDATE) ---
    if ($_POST['action'] === 'save') {
        $id = $_POST['content_id'] ?? '';
        $type = $_POST['tab_type']; // Announcements, Articles, Rates, Pictures
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $status = 'Active';
        
        // Handle Image Upload (For Pictures tab)
        $image_url = $_POST['existing_image'] ?? null;
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] == 0) {
            $targetDir = "../uploads/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            
            $fileName = time() . '_' . basename($_FILES["image_file"]["name"]);
            $targetFilePath = $targetDir . $fileName;
            $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
            
            if (in_array($fileType, ['jpg', 'jpeg', 'png', 'gif'])) {
                if (move_uploaded_file($_FILES["image_file"]["tmp_name"], $targetFilePath)) {
                    $image_url = "uploads/" . $fileName;
                }
            }
        }

        try {
            if (!empty($id)) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE website_content SET title=?, content=?, image_url=? WHERE id=?");
                $stmt->execute([$title, $content, $image_url, $id]);
                $_SESSION['msg'] = "Content successfully updated!";
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO website_content (type, title, content, image_url, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$type, $title, $content, $image_url, $status]);
                $_SESSION['msg'] = "New content successfully published!";
            }
            $_SESSION['msg_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['msg'] = "Error saving content: " . $e->getMessage();
            $_SESSION['msg_type'] = "error";
        }
    }

    // --- TOGGLE STATUS ---
    if ($_POST['action'] === 'toggle') {
        $id = $_POST['content_id'];
        $new_status = ($_POST['current_status'] === 'Active') ? 'Inactive' : 'Active';
        try {
            $pdo->prepare("UPDATE website_content SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            $_SESSION['msg'] = "Status updated.";
            $_SESSION['msg_type'] = "success";
        } catch (PDOException $e) {}
    }

    // --- DELETE ---
    if ($_POST['action'] === 'delete') {
        $id = $_POST['content_id'];
        try {
            $pdo->prepare("DELETE FROM website_content WHERE id = ?")->execute([$id]);
            $_SESSION['msg'] = "Content deleted permanently.";
            $_SESSION['msg_type'] = "success";
        } catch (PDOException $e) {}
    }

    header("Location: announcements.php?tab=" . $redirectTab);
    exit();
}

// ---------------------------------------------------------------------
// FETCH CONTENT FOR ACTIVE TAB
// ---------------------------------------------------------------------
$stmtAll = $pdo->prepare("SELECT * FROM website_content WHERE type = ? ORDER BY created_at DESC");
$stmtAll->execute([$activeTab]);
$contents = $stmtAll->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website CMS | NOCECO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        theme: {
          extend: {
            colors: { noceco: { bg: '#F5F5F7', mustard: '#DBA111', mustardHover: '#B8860B' } },
            fontFamily: { sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'] },
            boxShadow: { 'apple': '0 4px 24px rgba(0, 0, 0, 0.04)' }
          }
        }
      }
    </script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 10px; }
    </style>
</head>
<body class="bg-noceco-bg text-gray-900 flex h-screen overflow-hidden">

    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col justify-between hidden md:flex z-30 shrink-0">
        <div>
            <div class="h-20 flex items-center px-8 border-b border-gray-100">
                <div class="w-8 h-8 rounded-full bg-noceco-mustard flex items-center justify-center mr-3 shadow-apple-sm">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <span class="font-bold text-lg tracking-tight">NOCECO</span>
            </div>
            <nav class="p-4 space-y-1.5">
                <a href="admin-dashboard.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    Command Center
                </a>
                <a href="announcements.php" class="flex items-center px-4 py-3 bg-noceco-bg/80 text-noceco-mustard font-bold rounded-xl shadow-sm">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                    Web Content (CMS)
                </a>
                <a href="register-admin.php" class="flex items-center px-4 py-3 text-gray-500 hover:bg-gray-50 rounded-xl transition-colors">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                    Register Staff
                </a>
            </nav>
        </div>
        <div class="p-4 border-t border-gray-100">
            <a href="../logout.php" class="flex items-center px-4 py-3 text-red-500 hover:bg-red-50 rounded-xl font-medium">Logout</a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col h-screen overflow-hidden">
        
        <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 shrink-0 z-20">
            <div>
                <h2 class="text-xl font-bold tracking-tight">Content Management System</h2>
                <p class="text-xs text-gray-500">Manage the public website from this dashboard.</p>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden">
            
            <div class="w-56 bg-gray-50 border-r border-gray-200 overflow-y-auto py-6 shrink-0">
                <div class="px-6 mb-4"><h3 class="text-xs font-black text-gray-400 uppercase tracking-widest">Content Types</h3></div>
                <nav class="space-y-1 px-3">
                    <?php 
                    $tabs = [
                        'Announcements' => ['icon' => 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z'],
                        'Articles' => ['icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z'],
                        'Rates' => ['icon' => 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z'],
                        'Pictures' => ['icon' => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z']
                    ];
                    foreach($tabs as $tabName => $data):
                        $isActive = ($activeTab === $tabName);
                        $bgClass = $isActive ? 'bg-white shadow-sm border border-gray-200 text-noceco-mustard' : 'text-gray-600 hover:bg-gray-100 border border-transparent';
                    ?>
                        <a href="announcements.php?tab=<?php echo $tabName; ?>" class="flex items-center px-4 py-3 rounded-xl font-bold text-sm transition-all <?php echo $bgClass; ?>">
                            <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo $data['icon']; ?>"></path></svg>
                            Post <?php echo $tabName; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="flex-1 overflow-y-auto p-8 custom-scrollbar bg-noceco-bg">
                
                <?php if ($message): ?>
                    <div class="p-4 mb-6 rounded-xl text-sm font-bold <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-red-50 text-red-700 border border-red-100'; ?> flex items-center gap-3 shadow-sm">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="bg-white rounded-[24px] shadow-apple border border-gray-100 p-8 mb-8 relative">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900" id="formTitle">Create New <?php echo rtrim($activeTab, 's'); ?></h3>
                        <button type="button" onclick="resetForm()" id="btnCancelEdit" class="hidden text-xs font-bold text-red-500 hover:underline">Cancel Edit</button>
                    </div>

                    <form action="announcements.php" method="POST" enctype="multipart/form-data" class="space-y-5" id="cmsForm">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="tab_type" value="<?php echo $activeTab; ?>">
                        <input type="hidden" name="content_id" id="formContentId" value="">
                        <input type="hidden" name="existing_image" id="formExistingImage" value="">

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Headline / Title</label>
                            <input type="text" name="title" id="formHeadline" required placeholder="Enter the title..."
                                class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard outline-none transition-all text-sm font-bold">
                        </div>

                        <?php if($activeTab !== 'Pictures'): ?>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Details / Content</label>
                            <textarea name="content" id="formDetails" required rows="5" placeholder="Type the full details here..."
                                class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard outline-none transition-all text-sm"></textarea>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="content" value="Image Upload">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-2">Upload Image (JPG, PNG)</label>
                                <input type="file" name="image_file" id="formImage" accept="image/*"
                                    class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-noceco-mustard outline-none transition-all text-sm">
                                <p id="existingImageText" class="text-xs text-blue-500 font-bold mt-2 hidden">Current Image Attached.</p>
                            </div>
                        <?php endif; ?>

                        <div class="flex justify-end pt-2">
                            <button type="submit" id="btnSubmit" class="bg-gray-900 hover:bg-black text-white font-bold py-3 px-8 rounded-xl shadow-md transition-all">
                                Publish to Website
                            </button>
                        </div>
                    </form>
                </div>

                <section class="bg-white rounded-[24px] shadow-apple border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                        <h3 class="font-bold text-gray-900"><?php echo $activeTab; ?> Database</h3>
                        <span class="text-[10px] font-black bg-gray-900 text-white px-3 py-1 rounded-full uppercase"><?php echo count($contents); ?> Records</span>
                    </div>
                    <div class="overflow-x-auto max-h-[500px] overflow-y-auto custom-scrollbar">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-white sticky top-0 border-b border-gray-100 shadow-sm z-10">
                                <tr class="text-[10px] text-gray-400 uppercase tracking-widest">
                                    <th class="p-5 w-40">Date Posted</th>
                                    <th class="p-5">Information</th>
                                    <th class="p-5 text-center w-24">Status</th>
                                    <th class="p-5 text-right w-40">Actions (Edit/Del)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if(empty($contents)): ?>
                                    <tr><td colspan="4" class="p-10 text-center text-gray-400 italic">No <?php echo strtolower($activeTab); ?> found.</td></tr>
                                <?php else: foreach($contents as $c): ?>
                                <tr class="hover:bg-gray-50/80 transition-colors">
                                    <td class="p-5 text-gray-500 font-medium">
                                        <?php echo date('M d, Y', strtotime($c['created_at'])); ?><br>
                                        <span class="text-[10px]"><?php echo date('h:i A', strtotime($c['created_at'])); ?></span>
                                    </td>
                                    <td class="p-5">
                                        <p class="font-bold text-gray-900 mb-1"><?php echo htmlspecialchars($c['title']); ?></p>
                                        <?php if($activeTab !== 'Pictures'): ?>
                                            <p class="text-xs text-gray-500 line-clamp-2"><?php echo htmlspecialchars($c['content']); ?></p>
                                        <?php elseif(!empty($c['image_url'])): ?>
                                            <img src="../<?php echo $c['image_url']; ?>" alt="img" class="h-12 w-auto rounded border shadow-sm">
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-5 text-center">
                                        <form action="announcements.php?tab=<?php echo $activeTab; ?>" method="POST">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="tab_type" value="<?php echo $activeTab; ?>">
                                            <input type="hidden" name="content_id" value="<?php echo $c['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $c['status']; ?>">
                                            <button type="submit" class="px-3 py-1 rounded-full text-[10px] font-bold transition-all <?php echo $c['status'] === 'Active' ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-200 text-gray-500 hover:bg-gray-300'; ?>">
                                                <?php echo $c['status'] === 'Active' ? 'LIVE' : 'HIDDEN'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="p-5 text-right space-x-2">
                                        <button onclick="editContent('<?php echo $c['id']; ?>', '<?php echo addslashes($c['title']); ?>', '<?php echo addslashes($c['content']); ?>', '<?php echo $c['image_url']; ?>')" 
                                                class="text-blue-500 hover:text-blue-700 bg-blue-50 hover:bg-blue-100 p-2 rounded-lg transition-colors inline-block">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                        </button>
                                        
                                        <form action="announcements.php?tab=<?php echo $activeTab; ?>" method="POST" class="inline" onsubmit="return confirm('Permanently delete this?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="tab_type" value="<?php echo $activeTab; ?>">
                                            <input type="hidden" name="content_id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-2 rounded-lg transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>
        </div>
    </div>

    <script>
        function editContent(id, title, content, imageUrl) {
            // Populate Form Fields
            document.getElementById('formContentId').value = id;
            document.getElementById('formHeadline').value = title;
            
            const detailsField = document.getElementById('formDetails');
            if(detailsField) detailsField.value = content;
            
            const existingImgField = document.getElementById('formExistingImage');
            const imgText = document.getElementById('existingImageText');
            if(existingImgField && imgText && imageUrl) {
                existingImgField.value = imageUrl;
                imgText.classList.remove('hidden');
            }

            // Update UI to "Edit Mode"
            document.getElementById('formTitle').innerText = 'Edit Existing Post';
            document.getElementById('btnSubmit').innerText = 'Update Content';
            document.getElementById('btnSubmit').classList.replace('bg-gray-900', 'bg-blue-600');
            document.getElementById('btnSubmit').classList.replace('hover:bg-black', 'hover:bg-blue-700');
            document.getElementById('btnCancelEdit').classList.remove('hidden');

            // Scroll to top
            document.querySelector('.flex-1.overflow-y-auto').scrollTo({top: 0, behavior: 'smooth'});
        }

        function resetForm() {
            document.getElementById('cmsForm').reset();
            document.getElementById('formContentId').value = '';
            document.getElementById('formExistingImage').value = '';
            
            const imgText = document.getElementById('existingImageText');
            if(imgText) imgText.classList.add('hidden');

            document.getElementById('formTitle').innerText = 'Create New <?php echo rtrim($activeTab, 's'); ?>';
            document.getElementById('btnSubmit').innerText = 'Publish to Website';
            document.getElementById('btnSubmit').classList.replace('bg-blue-600', 'bg-gray-900');
            document.getElementById('btnSubmit').classList.replace('hover:bg-blue-700', 'hover:bg-black');
            document.getElementById('btnCancelEdit').classList.add('hidden');
        }
    </script>
</body>
</html>