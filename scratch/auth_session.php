<?php
chdir(dirname(__DIR__));
require_once __DIR__ . '/../api/session_guard.php';
$_SESSION['auth_user'] = [
    'id'          => 1,
    'name'        => 'Suresh Kumar',
    'email'       => 'suresh@softwarecrm.com',
    'role'        => 'admin',
    'status'      => 'active',
    'employee_id' => 'EMP-001'
];
session_write_close();

ob_start();
require __DIR__ . '/../index.php';
$html = ob_get_clean();

// Ensure base href is / so all relative scripts & assets resolve from CRM root
$html = str_replace('<head>', '<head><base href="/">', $html);

$injections = '';

if (isset($_GET['theme'])) {
    $th = $_GET['theme'] === 'dark' ? 'dark' : 'light';
    $injections .= '<script>
        try { localStorage.setItem("crm_theme", "' . $th . '"); } catch(e){}
        document.documentElement.setAttribute("data-theme", "' . $th . '");
        if ("' . $th . '" === "dark") {
            document.documentElement.classList.add("dark");
            document.documentElement.style.colorScheme = "dark";
        } else {
            document.documentElement.classList.remove("dark");
            document.documentElement.style.colorScheme = "light";
        }
        document.addEventListener("alpine:initialized", function() {
            if (window.Alpine && window.Alpine.$data) {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    el._x_dataStack[0].applyTheme("' . $th . '");
                }
            }
        });
    </script>';
}

if (isset($_GET['notifications']) && $_GET['notifications'] === '1') {
    $injections .= '<style>
        .crm-notification-panel,
        .crm-notification-panel * {
            transition-duration: 0s !important;
            animation-duration: 0s !important;
        }
        .crm-notification-panel.translate-x-full {
            transform: translateX(0) !important;
        }
    </style>
    <script>
        document.addEventListener("alpine:initialized", function() {
            var bell = document.querySelector("button[title=\'Notifications\']");
            if (bell) {
                bell.click();
            }
        });
    </script>';
}

if (isset($_GET['view'])) {
    $v = $_GET['view'];
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            var el = document.querySelector("[x-data]");
            if (el && el._x_dataStack && el._x_dataStack[0]) {
                el._x_dataStack[0].navigateTo("' . htmlspecialchars($v) . '");
            }
        });
    </script>';
}

if (isset($_GET['chat']) && $_GET['chat'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.openGroupChat();
                    ' . (isset($_GET['menu']) ? 'setTimeout(function() { if (app.chatMessages.length > 0) app.chatOpenMessageMenuId = app.chatMessages[app.chatMessages.length - 1].id; }, 800);' : '') . '
                }
            }, 500);
        });
    </script>';
}

if (isset($_GET['call']) && $_GET['call'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.openGroupChat();
                    app.modalGroupCallOpen = true;
                    app.callType = "' . ($_GET['call_type'] ?? 'video') . '";
                    app.callStatus = "connected";
                    app.callDurationFormatted = "04:28";
                    app.callParticipants = [
                        { user_id: "staff_1", user_name: "Suresh Kumar", user_role: "Lead Architect", is_muted: 0 },
                        { user_id: "staff_2", user_name: "Dhanush", user_role: "Senior Full Stack Dev", is_muted: 1 },
                        { user_id: "staff_3", user_name: "Akash Dev", user_role: "Engineering Manager", is_muted: 0 },
                        { user_id: "staff_4", user_name: "Priya Patel", user_role: "Product Designer", is_muted: 0 }
                    ];
                    ' . (isset($_GET['present']) ? 'app.isCallScreenSharing = true; app.activeCall = { presenter_name: "Suresh Kumar", is_presenting: 1 };' : '') . '
                }
            }, 500);
        });
    </script>';
}

if (isset($_GET['info']) && $_GET['info'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.openGroupChat();
                    if (app.chatMessages.length > 0) {
                        await app.openMessageInfo(app.chatMessages[app.chatMessages.length - 1]);
                    }
                }
            }, 600);
        });
    </script>';
}

if (isset($_GET['task_add']) && $_GET['task_add'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.loadTeamMembers();
                    app.openAddTaskScreen();
                    ' . (isset($_GET['assignees_modal']) ? '
                    setTimeout(function() {
                        app.openTaskAssigneesModal();
                    }, 400);' : '') . '
                    ' . (isset($_GET['with_chips']) ? '
                    setTimeout(function() {
                        var members = (app.teamMembers || []).slice(0, 3);
                        app.taskForm.assigned_member_ids = members.map(function(m){ return String(m.id); });
                        app.taskForm.selected_members = members;
                        app.taskForm.assigned_staff = members.map(function(m){ return m.name; }).join(", ");
                    }, 400);' : '') . '
                }
            }, 600);
        });
    </script>';
}

if (isset($_GET['task_detail']) && $_GET['task_detail'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.loadAllData();
                    var task = (app.tasks || []).find(function(t) { return t.assigned_members && t.assigned_members.length > 1; }) || (app.tasks && app.tasks[0]);
                    if (task) {
                        if (!task.assigned_members || task.assigned_members.length === 0) {
                            task.assigned_members = (app.teamMembers || []).slice(0, 3);
                            task.assigned_staff = task.assigned_members.map(function(m){ return m.name; }).join(", ");
                        }
                        app.viewTaskDetail(task);
                    }
                }
            }, 600);
        });
    </script>';
}

if (isset($_GET['task_edit']) && $_GET['task_edit'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.loadAllData();
                    var task = (app.tasks || []).find(function(t) { return t.assigned_members && t.assigned_members.length > 1; }) || (app.tasks && app.tasks[0]);
                    if (task) {
                        if (!task.assigned_members || task.assigned_members.length === 0) {
                            task.assigned_members = (app.teamMembers || []).slice(0, 3);
                            task.assigned_staff = task.assigned_members.map(function(m){ return m.name; }).join(", ");
                        }
                        app.openEditTaskScreen(task);
                    }
                }
            }, 600);
        });
    </script>';
}

if (isset($_GET['member_chart']) && $_GET['member_chart'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    var staff = (app.activeGroup && app.activeGroup.members && app.activeGroup.members.length > 0) ? app.activeGroup.members[0] : { id: "staff_1", name: "Suresh Kumar", role: "Lead Architect" };
                    await app.openMemberChartModal(staff);
                }
            }, 600);
        });
    </script>';
}

if (isset($_GET['create_group']) && $_GET['create_group'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    app.openCreateGroupModal();
                }
            }, 600);
        });
    </script>';
}

if (isset($_GET['edit_group']) && $_GET['edit_group'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    if (app.groups && app.groups.length > 0) {
                        var targetGroup = app.groups.find(function(g) { return g.name === "Development & Engineering"; }) || app.groups[0];
                        app.openEditGroupModal(targetGroup);
                    }
                }
            }, 600);
        });
    </script>';
}

if (isset($_GET['project_detail']) && $_GET['project_detail'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.loadAllData();
                    var p = app.projects.find(function(x) { return x.id === "proj_abc"; }) || app.projects[0];
                    app.viewProjectDetail(p);
                }
            }, 800);
        });
    </script>';
}

if (isset($_GET['project_modal']) && $_GET['project_modal'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.loadAllData();
                    app.openAddModal("project");
                    app.projectForm.title = "ABC";
                    app.onProjectTitleInput();
                }
            }, 800);
        });
    </script>';
}

if (isset($_GET['company_detail']) && $_GET['company_detail'] === '1') {
    $injections .= '<script>
        document.addEventListener("alpine:initialized", function() {
            setTimeout(async function() {
                var el = document.querySelector("[x-data]");
                if (el && el._x_dataStack && el._x_dataStack[0]) {
                    var app = el._x_dataStack[0];
                    await app.loadAllData();
                    var c = app.companies.find(function(x) { return x.id === "cmp_abc"; }) || app.companies[0];
                    await app.viewCompanyDetail(c);
                    app.companyDetailTab = "projects";
                }
            }, 800);
        });
    </script>';
}

if ($injections) {
    $html = str_replace('</body>', $injections . '</body>', $html);
}

echo $html;
