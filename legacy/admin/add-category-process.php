<?php

require_once "admin-auth.php";
require_once "../includes/csrf.php";
require_once "../includes/activity-log.php";


if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: categories.php");
    exit();

}

nb_csrf_check();


$category_name =
    trim($_POST["category_name"] ?? "");

$description =
    trim($_POST["description"] ?? "");

$icon =
    trim($_POST["icon"] ?? "fa-box-open");

$color =
    trim($_POST["color"] ?? "blue");

$status =
    $_POST["status"] ?? "active";


if (empty($category_name)) {

    die("Category name is required.");

}


if (
    $status !== "active" &&
    $status !== "inactive"
) {
    $status = "active";
}


$stmt = $conn->prepare("
    INSERT INTO categories
    (
        category_name,
        description,
        icon,
        color,
        status
    )
    VALUES (?, ?, ?, ?, ?)
");


$stmt->bind_param(
    "sssss",
    $category_name,
    $description,
    $icon,
    $color,
    $status
);


$stmt->execute();


nb_log_admin_action(
    $conn,
    $_SESSION["user_id"],
    "Category created",
    $category_name
);


header(
    "Location: categories.php"
);

exit();

?>