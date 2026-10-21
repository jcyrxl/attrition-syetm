</main>
<?php if (function_exists('isAdmin') && !isAdmin()) { include __DIR__ . '/preview_drawer.php'; } ?>
<script src="../assets/ui.js?v=1"></script>

<script>

    const menuButton = document.getElementById("menuButton");
    const sidebarOverlay = document.getElementById("sidebarOverlay");

    // The menu button: on a wide screen it collapses the sidebar to icons (so a wide table or class sheet gets the room); on a narrow one it slides it in.
    try { if (localStorage.getItem("sidebarCollapsed") === "1") document.body.classList.add("sidebar-collapsed"); } catch (e) {}
    menuButton.addEventListener("click", function () {
        if (window.innerWidth >= 1000) {
            var c = document.body.classList.toggle("sidebar-collapsed");
            try { localStorage.setItem("sidebarCollapsed", c ? "1" : "0"); } catch (e) {}
        } else {
            document.body.classList.toggle("sidebar-open");
        }
    });

    sidebarOverlay.addEventListener("click", function () {
        document.body.classList.remove("sidebar-open");
    });

    const profileButton = document.getElementById("profileButton");
    const profileDropdown = document.getElementById("profileDropdown");
    // The notification bell doesn't render at all for Admin (no student-data
    // access), so these can be null — every use below is null-guarded.
    const notifButton = document.getElementById("notifButton");
    const notifDropdown = document.getElementById("notifDropdown");

    profileButton.addEventListener("click", function (event) {
        event.stopPropagation();
        if (notifDropdown) notifDropdown.classList.remove("show");
        profileDropdown.classList.toggle("show");
    });

    if (notifButton && notifDropdown) {
        notifButton.addEventListener("click", function (event) {
            event.stopPropagation();
            profileDropdown.classList.remove("show");
            notifDropdown.classList.toggle("show");
        });
    }

    document.addEventListener("click", function (event) {
        if (!profileDropdown.contains(event.target) && !profileButton.contains(event.target)) {
            profileDropdown.classList.remove("show");
        }
        if (notifDropdown && !notifDropdown.contains(event.target) && !notifButton.contains(event.target)) {
            notifDropdown.classList.remove("show");
        }
    });


</script>
