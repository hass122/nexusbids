<?php

require_once "admin-auth.php";
require_once "../includes/csrf.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    nb_csrf_check();
}


$category_id =
    intval($_POST["category_id"] ?? 0);

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


$stmt = $conn->prepare("
    UPDATE categories
    SET
        category_name = ?,
        description = ?,
        icon = ?,
        color = ?,
        status = ?
    WHERE category_id = ?
");


$stmt->bind_param(
    "sssssi",
    $category_name,
    $description,
    $icon,
    $color,
    $status,
    $category_id
);


$stmt->execute();


header(
    "Location: categories.php"
);

exit();

?>