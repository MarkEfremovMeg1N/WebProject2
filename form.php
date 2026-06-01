<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'db.php';
require_once 'validation.php';

// ---------- Вспомогательные функции ----------
function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function generateLogin()
{
    return 'user_' . time() . '_' . bin2hex(random_bytes(4));
}

function generatePassword($length = 10)
{
    return bin2hex(random_bytes($length));
}

// ---------- Обработка выхода ----------
if (isset($_GET['logout'])) {
    session_destroy();
    redirect('form.php');
}

// ---------- Обработка входа (POST) ----------
$auth_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_action'])) {
    $login = $_POST['login'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, password_hash FROM my_applications WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            redirect('form.php?auth_success=1');
        } else {
            $_SESSION['auth_error'] = 'Неверный логин или пароль.';
            redirect('form.php');
        }
    } catch (PDOException $e) {
        $_SESSION['auth_error'] = 'Ошибка БД: ' . $e->getMessage();
        redirect('form.php');
    }
}

// ---------- Обработка отправки/редактирования анкеты ----------
$is_authenticated = isset($_SESSION['user_id']);
$current_user_id = $is_authenticated ? $_SESSION['user_id'] : null;

$user_data = null;
if ($is_authenticated) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM my_applications WHERE id = ?");
        $stmt->execute([$current_user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_application'])) {
    $input = [
        'full_name'   => trim($_POST['full_name'] ?? ''),
        'phone'       => trim($_POST['phone'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'birth_date'  => $_POST['birth_date'] ?? '',
        'gender'      => $_POST['gender'] ?? '',
        'languages'   => $_POST['languages'] ?? [],
        'bio'         => trim($_POST['bio'] ?? ''),
        'agreement'   => isset($_POST['agreement']) ? 1 : 0,
    ];

    $errors = [];
    $valid = validateApplicationData($input, $errors);

    if (!$valid) {
        setcookie('form_errors', json_encode($errors), 0, '/');
        setcookie('form_input', json_encode($input), 0, '/');
        redirect('form.php');
    }

    try {
        $pdo = getDB();
        $pdo->beginTransaction();

        if ($is_authenticated) {
            // Обновление
            $sql = "UPDATE my_applications 
                    SET full_name = ?, phone = ?, email = ?, birth_date = ?, gender = ?, bio = ?, agreement = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['full_name'],
                $input['phone'],
                $input['email'],
                $input['birth_date'],
                $input['gender'],
                $input['bio'],
                $input['agreement'],
                $current_user_id
            ]);
            $application_id = $current_user_id;

            $pdo->prepare("DELETE FROM my_application_languages WHERE application_id = ?")->execute([$application_id]);
            $stmt_lang = $pdo->prepare("INSERT INTO my_application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($input['languages'] as $lang_id) {
                $stmt_lang->execute([$application_id, $lang_id]);
            }

            $pdo->commit();
            setcookie('form_defaults', json_encode($input), time() + 365 * 86400, '/');
            setcookie('form_errors', '', 1, '/');
            setcookie('form_input', '', 1, '/');
            redirect('form.php?update_success=1');
        } else {
            // Новая запись
            $login = generateLogin();
            $plain_password = generatePassword();
            $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);

            $sql = "INSERT INTO my_applications 
                    (full_name, phone, email, birth_date, gender, bio, agreement, login, password_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['full_name'],
                $input['phone'],
                $input['email'],
                $input['birth_date'],
                $input['gender'],
                $input['bio'],
                $input['agreement'],
                $login,
                $password_hash
            ]);
            $application_id = $pdo->lastInsertId();

            $stmt_lang = $pdo->prepare("INSERT INTO my_application_languages (application_id, language_id) VALUES (?, ?)");
            foreach ($input['languages'] as $lang_id) {
                $stmt_lang->execute([$application_id, $lang_id]);
            }

            $pdo->commit();
            setcookie('form_defaults', json_encode($input), time() + 365 * 86400, '/');
            setcookie('form_errors', '', 1, '/');
            setcookie('form_input', '', 1, '/');

            $_SESSION['generated_credentials'] = ['login' => $login, 'password' => $plain_password];
            redirect('form.php?registered=1');
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        setcookie('form_errors', json_encode(['db' => 'Ошибка БД: ' . $e->getMessage()]), 0, '/');
        setcookie('form_input', json_encode($input), 0, '/');
        redirect('form.php');
    }
}

// ---------- Чтение Cookies и подготовка данных для формы ----------
$defaults = [];
$errors = [];
$input = [];

if (isset($_COOKIE['form_defaults'])) {
    $defaults = json_decode($_COOKIE['form_defaults'], true);
}
if (isset($_COOKIE['form_errors'])) {
    $errors = json_decode($_COOKIE['form_errors'], true);
    setcookie('form_errors', '', 1, '/');
}
if (isset($_COOKIE['form_input'])) {
    $input = json_decode($_COOKIE['form_input'], true);
    setcookie('form_input', '', 1, '/');
}

if ($is_authenticated && $user_data) {
    $defaults = [
        'full_name'   => $user_data['full_name'],
        'phone'       => $user_data['phone'],
        'email'       => $user_data['email'],
        'birth_date'  => $user_data['birth_date'],
        'gender'      => $user_data['gender'],
        'bio'         => $user_data['bio'],
        'agreement'   => $user_data['agreement'],
    ];
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT language_id FROM my_application_languages WHERE application_id = ?");
        $stmt->execute([$current_user_id]);
        $defaults['languages'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
    }
}

function get_field_value($field, $input, $defaults)
{
    if (isset($input[$field]) && $input[$field] !== '') {
        return htmlspecialchars($input[$field]);
    }
    if (isset($defaults[$field]) && $defaults[$field] !== '') {
        return htmlspecialchars($defaults[$field]);
    }
    return '';
}

function is_language_selected($lang_id, $input, $defaults)
{
    $selected = [];
    if (isset($input['languages']) && is_array($input['languages'])) {
        $selected = $input['languages'];
    } elseif (isset($defaults['languages']) && is_array($defaults['languages'])) {
        $selected = $defaults['languages'];
    }
    return in_array($lang_id, $selected);
}

$success_msg = '';
if (isset($_GET['registered'])) {
    $creds = $_SESSION['generated_credentials'] ?? null;
    if ($creds) {
        $success_msg = '<div class="alert alert-success">✅ Анкета сохранена!<br>
        <strong>Ваш логин:</strong> ' . htmlspecialchars($creds['login']) . '<br>
        <strong>Ваш пароль:</strong> ' . htmlspecialchars($creds['password']) . '<br>
        Сохраните эти данные для последующего редактирования.</div>';
        unset($_SESSION['generated_credentials']);
    } else {
        $success_msg = '<div class="alert alert-success">✅ Анкета успешно сохранена!</div>';
    }
} elseif (isset($_GET['update_success'])) {
    $success_msg = '<div class="alert alert-success">✅ Данные успешно обновлены!</div>';
} elseif (isset($_GET['auth_success'])) {
    $success_msg = '<div class="alert alert-success">✅ Вы успешно вошли в систему. Теперь вы можете редактировать свои данные.</div>';
}
$auth_error = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drupal Coder - Поддержка сайтов на Drupal</title>
    <link href="https://fonts.googleapis.com/css?family=Montserrat&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css?family=Ubuntu&display=swap" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <section id="header" class="header-section">
  <div class="header-bg">
    <video autoplay loop muted playsinline class="header-bg-image" alt="Background">
      <source src="video.mp4" type="video/mp4">
      <source src="video.webm" type="video/webm">
      Ваш браузер не поддерживает видео
    </video>
    
    <div class="header-bg-overlay">
      <div class="header-bg-druplicon">
        <img src="druplicon.svg" alt="Druplicon background shape cutout" />
      </div>
    </div>
  </div>
  
  <div class="container">
    <header class="site-header">
      <a href="#" class="logo">
        <img src="drupal-coder.svg" alt="Drupal Coder Logo" />
      </a>
      
      <!-- Гамбургер меню для мобильных -->
      <button class="hamburger" aria-label="Открыть меню">
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
        <span class="hamburger-line"></span>
      </button>
      
      <nav class="main-nav">
        <ul>
          <li><a href="#support">Поддержка сайтов</a></li>
          <li><a href="#pricing">Тарифы</a></li>
          <li><a href="#cases">Наши работы</a></li>
          <li><a href="#testimonials">Отзывы</a></li>
          <li><a href="#footer">Контакты</a></li>
        </ul>
      </nav>
      
      <div class="header-contact">
        <a href="tel:88002222673" class="phone-number">8 800 222-26-73</a>
        
        <!-- Выпадающее меню языков -->
        <div class="lang-switcher">
          <div class="lang-current">
            <span>RU</span>
            <img src="arrow-lang.svg" alt="language dropdown arrow" class="arr"/>
          </div>
          <ul class="lang-dropdown">
            <li><a href="#" data-lang="en">English (EN)</a></li>
            <li><a href="#" data-lang="de">Deutsch (DE)</a></li>
            <li><a href="#" data-lang="fr">Français (FR)</a></li>
            <li><a href="#" data-lang="es">Español (ES)</a></li>
            <li><a href="#" data-lang="zh">中文 (ZH)</a></li>
          </ul>
        </div>
      </div>
    </header>
    
    <!-- Мобильное меню (полноэкранное) -->
    <div class="mobile-menu-overlay">
      <div class="mobile-menu-container">
        <div class="mobile-menu-header">
          <h2 class="mobile-menu-title">Поддержка сайтов на Drupal</h2>
          <button class="mobile-menu-close" aria-label="Закрыть меню">
            <span>&times;</span>
          </button>
        </div>
        
        <nav class="mobile-nav">
          <ul>
            <li><a href="#support">Поддержка сайтов</a></li>
            <li><a href="#pricing">Тарифы</a></li>
            <li><a href="#cases">Наши работы</a></li>
            <li><a href="#testimonials">Отзывы</a></li>
            <li><a href="#footer">Контакты</a></li>
          </ul>
          
          <div class="mobile-lang-switcher">
            <div class="mobile-lang-current">
              <span>Язык: Русский (RU)</span>
              <img src="arrow-lang.svg" alt="language dropdown arrow" class="arr"/>
            </div>
            <ul class="mobile-lang-dropdown">
              <li><a href="#" data-lang="en">English (EN)</a></li>
              <li><a href="#" data-lang="de">Deutsch (DE)</a></li>
              <li><a href="#" data-lang="fr">Français (FR)</a></li>
              <li><a href="#" data-lang="es">Español (ES)</a></li>
              <li><a href="#" data-lang="zh">中文 (ZH)</a></li>
            </ul>
          </div>
        </nav>
        
        <div class="mobile-contact">
          <a href="tel:88002222673" class="mobile-phone-number">
            <img src="phone-line (2).svg" alt="phone icon" />
            <span>8 800 222-26-73</span>
          </a>
        </div>
      </div>
    </div>
    
    <main class="hero-content">
      <div class="hero-text">
        <h1>Поддержка сайтов на Drupal</h1>
        <p>
          Сопровождение и поддержка сайтов на CMS Drupal любых версий и
          запущенности
        </p>
        <a href="#pricing" class="btn btn-secondary hero-btn">Тарифы</a>
      </div>
      <div class="hero-stats">
        <!-- Статистика остается без изменений -->
        <div class="stat-item">
          <div class="stat-line"></div>
          <div class="stat-content">
            <div class="stat-title">
              <span class="stat-main-title">#1</span>
              <img src="cup.png" alt="Trophy icon" class="stat-icon" />
            </div>
            <p>Drupal-разработчик в России по версии Рейтинга Рунета</p>
          </div>
        </div>
        <div class="stat-item">
          <div class="stat-line"></div>
          <p class="stat-content">
            <strong>3+</strong><span>средний опыт специалистов более 3 лет</span>
          </p>
        </div>
        <div class="stat-item">
          <div class="stat-line"></div>
          <p class="stat-content">
            <strong>14</strong><span>лет опыта в сфере Drupal</span>
          </p>
        </div>
        <div class="stat-item">
          <div class="stat-line"></div>
          <p class="stat-content">
            <strong>200+</strong><span>Проектов на поддержке</span>
          </p>
        </div>
        <div class="stat-item">
          <div class="stat-line"></div>
          <p class="stat-content">
            <strong>35 000</strong><span>часов поддержки сайтов на Drupal</span>
          </p>
        </div>
        <div class="stat-item">
          <div class="stat-line"></div>
          <p class="stat-content">
            <strong>200+</strong><span>модулей и тем в формате DrupalGive</span>
          </p>
        </div>
      </div>
    </main>
  </div>
</section>

<section id="services" class="services-section">
  <div class="container">
    <h2 class="services-title">
      13 лет совершенствуем компетенции в Drupal поддержке!
    </h2>
    <p class="services-subtitle">
      Разрабатываем и оптимизируем модули, расширяем функциональность сайтов,
      обновляем дизайн
    </p>
    <div class="services-grid">
      <div class="service-card">
        <div class="service-icon-wrapper">
          <!-- merged image -->
          <img src="Vector.svg" alt="icon background" />
          <img
            src="competency-1.svg"
            alt="Добавление информации иконка"
          />
        </div>
        <p>Добавление информации на сайт, создание новых разделов</p>
      </div>
      <div class="service-card">
        <div class="service-icon-wrapper">
          <!-- merged image -->
          <img src="Vector.svg" alt="icon background" />
          <img
            src="competency-2.svg"
            alt="Разработка и оптимизация иконка"
          />
          <!-- <img src="${ASSET_PATH}/I2_726_82_2226_82_2009.svg" alt="" />
          <img src="${ASSET_PATH}/I2_726_82_2226_82_2016.svg" alt="" />
          <img src="${ASSET_PATH}/I2_726_82_2226_82_2017.svg" alt="" />
          <img src="${ASSET_PATH}/I2_726_82_2226_82_2018.svg" alt="" />
          <img src="${ASSET_PATH}/I2_726_82_2226_82_2019.svg" alt="" />
          <img src="${ASSET_PATH}/I2_726_82_2226_82_2020.svg" alt="" />
          <img src="${ASSET_PATH}/I2_726_82_2226_82_2013.svg" alt="" />
          <img src="${ASSET_PATH}/I2_726_82_2226_82_2014.svg" alt="" /> -->
        </div>
        <p>Разработка и оптимизация модулей сайта</p>
      </div>
      <div class="service-card">
        <div class="service-icon-wrapper">
          <!-- merged image -->
          <img src="Vector.svg" alt="icon background" />
          <img
            src="competency-3.svg"
            alt="Интеграция иконка"
          />
          <!-- <img src="${ASSET_PATH}/I2_727_82_2226_82_2040.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2041.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2042.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2056.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2057.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2043.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2052.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2044.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2025.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2026.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2045.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2047.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2048.svg" alt="" />
          <img src="${ASSET_PATH}/I2_727_82_2226_82_2049.svg" alt="" /> -->
        </div>
        <p>Интеграция с CRM, 1C, платежными системами, любыми веб-сервисами</p>
      </div>
      <div class="service-card">
        <div class="service-icon-wrapper">
          <!-- merged image -->
          <img src="Vector.svg" alt="icon background" />
          <img
            src="competency-4.svg"
            alt="Доработки функционала иконка"
          />
          <!-- <img src="${ASSET_PATH}/I2_731_82_2226_82_2078.svg" alt="" />
          <img src="${ASSET_PATH}/I2_731_82_2226_82_2089.svg" alt="" />
          <img src="${ASSET_PATH}/I2_731_82_2226_82_2090.svg" alt="" />
          <img src="${ASSET_PATH}/I2_731_82_2226_82_2098.svg" alt="" />
          <img src="${ASSET_PATH}/I2_731_82_2226_82_2099.svg" alt="" />
          <img src="${ASSET_PATH}/I2_731_82_2226_82_2100.svg" alt="" />
          <img src="${ASSET_PATH}/I2_731_82_2226_82_2097.svg" alt="" />
          <img src="${ASSET_PATH}/I2_731_82_2226_82_2092.svg" alt="" />
          <img src="${ASSET_PATH}/I2_731_82_2226_82_2091.svg" alt="" /> -->
        </div>
        <p>Любые доработки функционала и дизайна</p>
      </div>
      <div class="service-card">
        <div class="service-icon-wrapper">
          <!-- merged image -->
          <img src="Vector.svg" alt="icon background" />
          <img
            src="competency-5.svg"
            alt="Аудит и мониторинг иконка"
          />
          <!-- <img src="${ASSET_PATH}/I2_728_82_2226_82_2114.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2115.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2117.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2116.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2104.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2105.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2118.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2127.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2119.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2120.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2121.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2122.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2123.svg" alt="" />
          <img src="${ASSET_PATH}/I2_728_82_2226_82_2124.svg" alt="" /> -->
        </div>
        <p>Аудит и мониторинг безопасности Drupal сайтов</p>
      </div>
      <div class="service-card">
        <div class="service-icon-wrapper">
          <!-- merged image -->
          <img src="Vector.svg" alt="icon background" />
          <img
            src="competency-6.svg"
            alt="Миграция и импорт иконка"
          />
          <!-- <img src="${ASSET_PATH}/I2_729_82_2226_82_2161.svg" alt="" />
          <img src="${ASSET_PATH}/I2_729_82_2226_82_2162.svg" alt="" />
          <img src="${ASSET_PATH}/I2_729_82_2226_82_2165.svg" alt="" /> -->
        </div>
        <p>Миграция, импорт контента и апгрейд Drupal</p>
      </div>
      <div class="service-card">
        <div class="service-icon-wrapper">
          <!-- merged image -->
          <img src="Vector.svg" alt="icon background" />
          <img
            src="competency-7.svg"
            alt="Оптимизация и ускорение иконка"
          />
          <!-- <img src="${ASSET_PATH}/I2_724_82_2226_82_2217.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2194.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2195.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2196.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2197.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2198.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2199.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2200.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2201.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2202.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2203.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2205.svg" alt="" />
          <img src="${ASSET_PATH}/I2_724_82_2226_82_2206.svg" alt="" /> -->
        </div>
        <p>Оптимизация и ускорение Drupal-сайтов</p>
      </div>
      <div class="service-card">
        <div class="service-icon-wrapper">
          <!-- merged image -->
          <img src="Vector.svg" alt="icon background" />
          <img
            src="competency-8.svg"
            alt="Веб-маркетинг и SEO иконка"
          />
          <!-- <img src="${ASSET_PATH}/I2_730_82_2226_82_3448.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3447.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3432.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3433.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3434.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3437.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3438.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3436.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3449.svg" alt="" />
          <img src="${ASSET_PATH}/I2_730_82_2226_82_3450.svg" alt="" /> -->
        </div>
        <p>Веб-маркетинг, консультации и работы по SEO</p>
      </div>
    </div>
  </div>
</section>

<section id="support" class="support-section">
  <div class="support-bg-elements">
    <!-- <img
      src="${ASSET_PATH}/2_506.svg"
      alt="background druplicon"
      class="support-bg-druplicon"
    /> -->
    
  </div>
  <div class="container">
    <h2 class="support-title">Поддержка от Drupal-coder</h2>
    <div class="support-grid">
      <div class="support-card">
        <div class="support-card-header">
          <span>01.</span>
          <h3>Постановка задачи по Email</h3>
        </div>
        <p>
          Удобная и привычная модель постановки задач, при которой задачи
          фиксируются и никогда не теряются.
        </p>
        <img
          src="support1.svg"
          alt="icon"
          class="support-card-icon"
        />
      </div>
      <div class="support-card">
        <div class="support-card-header">
          <span>02.</span>
          <h3>Система Helpdesk – отчетность, прозрачность</h3>
        </div>
        <p>
          Возможность посмотреть все заявки в работе и отработанные часы в
          личном кабинете через браузер.
        </p>
        <img
          src="support2.svg"
          alt="icon"
          class="support-card-icon"
        />
      </div>
      <div class="support-card">
        <div class="support-card-header">
          <span>03.</span>
          <h3>Расширенная техническая поддержка</h3>
        </div>
        <p>
          Возможность организации расширенной техподдержки с 6:00 до 22:00 без
          выходных.
        </p>
        <img
          src="support3.svg"
          alt="icon"
          class="support-card-icon"
        />
      </div>
      <div class="support-card">
        <div class="support-card-header">
          <span>04.</span>
          <h3>Персональный менеджер проекта</h3>
        </div>
        <p>
          Ваш менеджер проекта всегда в курсе текущего состояния проекта и в
          любой момент готов ответить на любые вопросы.
        </p>
        <img
          src="support4.svg"
          alt="icon"
          class="support-card-icon"
        />
      </div>
      <div class="support-card">
        <div class="support-card-header">
          <span>05.</span>
          <h3>Удобные способы оплаты</h3>
        </div>
        <p>
          Безналичный расчет по договору или электронные деньги: WebMoney,
          Яндекс.Деньги, Paypal.
        </p>
        <img
          src="support5.svg"
          alt="icon"
          class="support-card-icon"
        />
      </div>
      <div class="support-card">
        <div class="support-card-header">
          <span>06.</span>
          <h3>Работаем с SLA и NDA</h3>
        </div>
        <p>
          Работа в рамках соглашений о конфиденциальности и об уровне качетсва
          работ.
        </p>
        <img
          src="support6.svg"
          alt="icon"
          class="support-card-icon"
        />
      </div>
      <div class="support-card">
        <div class="support-card-header">
          <span>07.</span>
          <h3>Штатные специалисты</h3>
        </div>
        <p>Надежные штатные специалисты, никаких фрилансеров.</p>
        <img
          src="support7.svg"
          alt="icon"
          class="support-card-icon"
        />
      </div>
      <div class="support-card">
        <div class="support-card-header">
          <span>08.</span>
          <h3>Удобные каналы связи</h3>
        </div>
        <p>Консультации по телефону, скайпу, в месенджерах.</p>
        <img
          src="support8.svg"
          alt="icon"
          class="support-card-icon"
        />
      </div>
    </div>
    <div class="expertise-section">
      <!-- Добавляем ноутбук внутрь expertise-section -->
      <div class="expertise-laptop">
        <img src="laptop.png" alt="laptop base" />
      </div>
      <div class="expertise-content">
        <h2>Экспертиза в Drupal, опыт 14 лет!</h2>
        <div class="expertise-points-horizontal">
          <div class="expertise-point">
            <div class="expertise-line"></div>
            <p>
              Только системный подход – контроль версий, резервирование и
              тестирование!
            </p>
          </div>
          <div class="expertise-point">
            <div class="expertise-line"></div>
            <p>Только Drupal сайты, не берем на поддержку сайты на других CMS!</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="pricing" class="pricing-section">
  <div class="pricing-bg">
    <!-- merged image -->
    <!-- <img src="${ASSET_PATH}/2_417.svg" alt="background shape" />
    <img src="${ASSET_PATH}/2_434.svg" alt="background shape highlight" /> -->
  </div>
  <div class="container">
    <h2 class="pricing-title">Тарифы</h2>
    <div class="pricing-cards">
      <div class="price-card">
        <h3 class="price-card-title">Стартовый</h3>
        <div class="price-tag">
          1500 ₽ <span class="price-period">в час</span>
        </div>
        <hr class="price-divider" />
        <ul>
          <li>
            <img src="checked.svg" alt="check" /> Предоплата
            от 2 часов
          </li>
          <li>
            <img src="checked.svg" alt="check" />
            Консультации и работы по SEO
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Услуги
            дизайнера
          </li>
          <li>
            <img src="checked.svg" alt="check" />
            Стандартное время реакции
          </li>
          <li>
            <img src="checked.svg" alt="check" />
            Неиспользованные оплаченные часы переносятся на следующий месяц
          </li>
        </ul>
        <a href="#footer" class="btn btn-secondary">Оставить заявку!</a>
      </div>
      <div class="price-card highlighted">
        <h3 class="price-card-title">Бизнес</h3>
        <div class="price-tag">
          2000 ₽ <span class="price-period">в час</span>
        </div>
        <hr class="price-divider" />
        <ul>
          <li>
            <img src="checked.svg" alt="check" /> Предоплата от 10
            часов
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Консультации и
            работы по SEO
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Услуги дизайнера
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Высокое время
            реакции – до 2 рабочих дней
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Неиспользованные
            часы не переносятся
          </li>
        </ul>
        <a href="#footer" class="btn btn-primary">Оставить заявку!</a>
      </div>
      <div class="price-card">
        <h3 class="price-card-title">VIP</h3>
        <div class="price-tag">
          2500 ₽ <span class="price-period">в час</span>
        </div>
        <hr class="price-divider" />
        <ul>
          <li>
            <img src="checked.svg" alt="check" /> Предоплата от 100
            часов
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Консультации и
            работы по SEO
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Услуги дизайнера
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Максимальное время
            реакции – в день обращения
          </li>
          <li>
            <img src="checked.svg" alt="check" /> Неиспользованные
            часы не переносятся
          </li>
        </ul>
        <a href="#footer" class="btn btn-secondary">Оставить заявку!</a>
      </div>
    </div>
    <div class="pricing-footer">
      <p>
        Вам не подходят наши тарифы? Оставьте заявку и мы предложим вам
        индивидуальные условия!
      </p>
      <a href="#footer">Получить индивидуальный тариф</a>
    </div>
  </div>
</section>
<section id="developer-form" class="developer-form-section">
<div class="container">
<div class="anketa-container">
        <div class="logout-link">
            <?php if ($is_authenticated): ?><a href="?logout=1">Выйти</a><?php endif; ?>
        </div>

        <?php if (!$is_authenticated): ?>
            <div class="login-form">
                <h3>Вход для редактирования</h3>
                <form method="POST">
                    <input type="hidden" name="login_action" value="1">
                    <div class="form-group"><label>Логин: <input type="text" name="login" required></label></div>
                    <div class="form-group"><label>Пароль: <input type="password" name="password" required></label></div>
                    <button type="submit" class="btn-submit" style="width:auto;">Войти</button>
                    <?php if ($auth_error): ?><div class="error-message"><?= htmlspecialchars($auth_error) ?></div><?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <?= $success_msg ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">❌ При заполнении формы допущены ошибки. Исправьте их и отправьте снова.</div>
        <?php endif; ?>

        <form method="POST" id="anketa-form">
            <input type="hidden" name="save_application" value="1">

            <!-- ФИО -->
            <div class="form-group">
                <label for="full_name" class="required">ФИО</label>
                <input type="text" name="full_name" id="full_name" value="<?= get_field_value('full_name', $input, $defaults) ?>" class="<?= isset($errors['full_name']) ? 'input-error' : '' ?>">
                <?php if (isset($errors['full_name'])): ?><div class="error-message"><?= htmlspecialchars($errors['full_name']) ?></div><?php endif; ?>
            </div>

            <!-- Телефон -->
            <div class="form-group">
                <label for="phone" class="required">Телефон</label>
                <input type="tel" name="phone" id="phone" value="<?= get_field_value('phone', $input, $defaults) ?>" class="<?= isset($errors['phone']) ? 'input-error' : '' ?>">
                <?php if (isset($errors['phone'])): ?><div class="error-message"><?= htmlspecialchars($errors['phone']) ?></div><?php endif; ?>
            </div>

            <!-- Email -->
            <div class="form-group">
                <label for="email" class="required">E-mail</label>
                <input type="email" name="email" id="email" value="<?= get_field_value('email', $input, $defaults) ?>" class="<?= isset($errors['email']) ? 'input-error' : '' ?>">
                <?php if (isset($errors['email'])): ?><div class="error-message"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
            </div>

            <!-- Дата рождения -->
            <div class="form-group">
                <label for="birth_date" class="required">Дата рождения</label>
                <input type="date" name="birth_date" id="birth_date" value="<?= get_field_value('birth_date', $input, $defaults) ?>" class="<?= isset($errors['birth_date']) ? 'input-error' : '' ?>">
                <?php if (isset($errors['birth_date'])): ?><div class="error-message"><?= htmlspecialchars($errors['birth_date']) ?></div><?php endif; ?>
            </div>

            <!-- Пол -->
            <div class="form-group">
                <label class="required">Пол</label>
                <div class="radio-group">
                    <label><input type="radio" name="gender" value="male" <?= get_field_value('gender', $input, $defaults) == 'male' ? 'checked' : '' ?>> Мужской</label>
                    <label><input type="radio" name="gender" value="female" <?= get_field_value('gender', $input, $defaults) == 'female' ? 'checked' : '' ?>> Женский</label>
                    <label><input type="radio" name="gender" value="other" <?= get_field_value('gender', $input, $defaults) == 'other' ? 'checked' : '' ?>> Другой</label>
                </div>
                <?php if (isset($errors['gender'])): ?><div class="error-message"><?= htmlspecialchars($errors['gender']) ?></div><?php endif; ?>
            </div>

            <!-- Любимые языки программирования -->
            <div class="form-group">
                <label for="languages" class="required">Любимые языки программирования</label>
                <select name="languages[]" id="languages" multiple size="6" class="<?= isset($errors['languages']) ? 'input-error' : '' ?>">
                    <?php
                    $lang_list = [1 => 'Pascal', 2 => 'C', 3 => 'C++', 4 => 'JavaScript', 5 => 'PHP', 6 => 'Python', 7 => 'Java', 8 => 'Haskell', 9 => 'Clojure', 10 => 'Prolog', 11 => 'Scala', 12 => 'Go'];
                    foreach ($lang_list as $id => $name):
                        $selected = is_language_selected($id, $input, $defaults);
                    ?>
                        <option value="<?= $id ?>" <?= $selected ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Удерживайте Ctrl (Cmd на Mac) для выбора нескольких</small>
                <?php if (isset($errors['languages'])): ?><div class="error-message"><?= htmlspecialchars($errors['languages']) ?></div><?php endif; ?>
            </div>

            <!-- Биография -->
            <div class="form-group">
                <label for="bio" class="required">Биография</label>
                <textarea name="bio" id="bio" rows="5" class="<?= isset($errors['bio']) ? 'input-error' : '' ?>"><?= get_field_value('bio', $input, $defaults) ?></textarea>
                <?php if (isset($errors['bio'])): ?><div class="error-message"><?= htmlspecialchars($errors['bio']) ?></div><?php endif; ?>
            </div>

            <!-- Согласие -->
            <div class="checkbox-group">
                <label>
                    <input type="checkbox" name="agreement" value="1" <?= get_field_value('agreement', $input, $defaults) == 1 ? 'checked' : '' ?>>
                    Я ознакомлен(а) с контрактом и согласен(на)
                </label>
                <?php if (isset($errors['agreement'])): ?><div class="error-message"><?= htmlspecialchars($errors['agreement']) ?></div><?php endif; ?>
            </div>

            <button type="submit" class="btn-submit"><?= $is_authenticated ? 'Обновить данные' : 'Сохранить' ?></button>
        </form>
    </div>
</div>
</section>


<section id="cases" class="cases-section">
  <div class="container">
    <h2 class="cases-title">Последние кейсы</h2>
    <div class="cases-grid">
      <a href="#" class="case-card case-card-1">
        <div class="case-card-bg">
          <!-- merged image -->
          <img
            src="image 6.2.png"
            alt="Case background"
          />
          <!-- <img
            src="${ASSET_PATH}/4dafacc165b526b404520c4eea02f6792454684e.png"
            alt="Case background"
          />
          <img
            src="${ASSET_PATH}/e8944c2dee65fbb404111995265ed608c0f68104.png"
            alt="Case background"
          />
          <img
            src="${ASSET_PATH}/12a3191032ce9867937009f986c4177c5f9f88da.png"
            alt="Case background"
          /> -->
        </div>
        <div class="case-card-content">
          <h3>Настройка выгрузки YML для Яндекс.Маркета</h3>
          <p class="case-date">22.04.2019</p>
          <p class="case-desc">
            Эти слова совершенно справедливы, однако гипнотический рифф
            продолжает паузный пласт.
          </p>
        </div>
      </a>
      <a href="#" class="case-card case-card-2">
        <div class="case-card-bg">
          <!-- merged image -->
          <img
            src="image 6.3.png"
            alt="Case background"
          />
          <!-- <img
            src="${ASSET_PATH}/4dafacc165b526b404520c4eea02f6792454684e.png"
            alt="Case background"
          />
          <img
            src="${ASSET_PATH}/e8944c2dee65fbb404111995265ed608c0f68104.png"
            alt="Case background"
          /> -->
        </div>
        <div class="case-card-content overlay">
          <h3>Настройка выгрузки YML для Яндекс.Маркета</h3>
          <p class="case-date">22.04.2019</p>
        </div>
      </a>
      <a href="#" class="case-card case-card-3">
        <div class="case-card-bg">
          <!-- merged image -->
          <img
            src="image 7.3.png"
            alt="Case background"
          />
        </div>
        <div class="case-card-content">
          <h3>Настройка выгрузки YML для Яндекс.Маркета</h3>
          <p class="case-date">22.04.2019</p>
          <p class="case-desc">
            Эти слова совершенно справедливы, однако гипнотический рифф
            продолжает паузный пласт.
          </p>
        </div>
      </a>
      <a href="#" class="case-card case-card-4">
        <div class="case-card-bg">
          <!-- merged image -->
          <img
            src="image 8.3.png"
            alt="Case background"
          />
          <!-- <img
            src="${ASSET_PATH}/4dafacc165b526b404520c4eea02f6792454684e.png"
            alt="Case background"
          />
          <img
            src="${ASSET_PATH}/e8944c2dee65fbb404111995265ed608c0f68104.png"
            alt="Case background"
          /> -->
        </div>
        <div class="case-card-content overlay">
          <h3>Настройка выгрузки YML для Яндекс.Маркета</h3>
          <p class="case-date">22.04.2019</p>
        </div>
      </a>
      <a href="#" class="case-card case-card-5">
        <div class="case-card-bg">
          <!-- merged image -->
          <img
            src="image 9.2.png"
            alt="Case background"
          />
          <!-- <img
            src="${ASSET_PATH}/4dafacc165b526b404520c4eea02f6792454684e.png"
            alt="Case background"
          /> -->
        </div>
        <div class="case-card-content overlay">
          <h3>Настройка выгрузки YML для Яндекс.Маркета</h3>
          <p class="case-date">22.04.2019</p>
        </div>
      </a>
      
      <!-- Новая карточка 6 (занимает 2/3 третьего ряда на десктопе) -->
      <a href="#" class="case-card case-card-6">
        <div class="case-card-bg">
          <!-- merged image -->
          <img
            src="image 10.1.png"
            alt="Case background"
          />
          <!-- <img
            src="${ASSET_PATH}/4dafacc165b526b404520c4eea02f6792454684e.png"
            alt="Case background"
          />
          <img
            src="${ASSET_PATH}/e8944c2dee65fbb404111995265ed608c0f68104.png"
            alt="Case background"
          /> -->
        </div>
        <div class="case-card-content overlay">
          <h3>Оптимизация контекстной рекламы</h3>
          <p class="case-date">15.03.2019</p>
          <p class="case-desc">
            Повысили конверсию на 30% для клиента из сферы e-commerce
            за счет грамотной настройки рекламных кампаний.
          </p>
        </div>
      </a>
      
      <!-- Новая карточка 7 (занимает 1/3 третьего ряда на десктопе) -->
      <a href="#" class="case-card case-card-7">
        <div class="case-card-bg">
          <!-- merged image -->
          <img
            src="image 11.1.png"
            alt="Case background"
          />
          <!-- <img
            src="${ASSET_PATH}/4dafacc165b526b404520c4eea02f6792454684e.png"
            alt="Case background"
          /> -->
        </div>
        <div class="case-card-content">
          <h3>Аудит сайта и техническая оптимизация</h3>
          <p class="case-date">10.02.2019</p>
          <p class="case-desc">
            Провели комплексный аудит и устранили 95% технических
            ошибок, что улучшило позиции сайта в поисковой выдаче.
          </p>
        </div>
      </a>
    </div>
    <div class="cases-footer">
      <button class="btn btn-secondary">Показать ещё</button>
    </div>
  </div>
</section>

<section id="team" class="team-section">
  <div class="container">
    <h2 class="team-title">Команда</h2>
    <div class="team-grid">
      <div class="team-member">
        <img
          src="v2_382.png"
          alt="Лёша"
        />
        <h3>Лёша</h3>
        <p>руководитель поддержки, планирование задач</p>
      </div>
      <div class="team-member">
        <img
          src="v2_400.png"
          alt="Роман"
        />
        <h3>Роман</h3>
        <p>инфраструктура веб-проектов</p>
      </div>
      <div class="team-member">
        <img
          src="v2_395.png"
          alt="Ирина"
        />
        <h3>Ирина</h3>
        <p>менеджер по работе с клинетами, организация оказания услуг</p>
      </div>
      <div class="team-member">
        <img
          src="v2_395.png"
          alt="Даша"
        />
        <h3>Даша</h3>
        <p>SEO, веб-маркетинг</p>
      </div>
      <div class="team-member">
        <img
          src="v2_387.png"
          alt="Сергей"
        />
        <h3>Сергей</h3>
        <p>технический директор, 14 лет опыт работы с Drupal</p>
      </div>
      <div class="team-member">
        <img
          src="v2_405.png"
          alt="Вадим"
        />
        <h3>Вадим</h3>
        <p>UX/UI дизайн</p>
      </div>
    </div>
  </div>
</section>

<section id="testimonials" class="testimonials-section">
  <div class="testimonial-bg-quote">
    <img src="${ASSET_PATH}/2_361.svg" alt="quote icon" />
  </div>
  <div class="container">
    <h2 class="testimonials-title">Отзывы</h2>
    <div class="testimonial-slider">
      <div class="testimonial-card-bg-1"></div>
      <div class="testimonial-card-bg-2"></div>
      
      <!-- Контейнер для слайдов -->
      <div class="testimonial-slides">
        <!-- Слайд 1 -->
        <div class="testimonial-slide active" data-index="0">
          <div class="testimonial-content">
            <img src="v2_373.png" alt="Winamp Logo" class="testimonial-logo" />
            <h3>Команда Drupal Coder вызвала только положительные впечатления!</h3>
            <p>Нуреев Александр, менеджер проекта Winamp Russian Community</p>
          </div>
        </div>
        
        <!-- Слайд 2 -->
        <div class="testimonial-slide" data-index="1">
          <div class="testimonial-content">
            <img src="v2_373.png" alt="Logo 2" class="testimonial-logo" />
            <h3>Отличная работа! Быстро и качественно.</h3>
            <p>Иванов Иван, директор компании "Пример"</p>
          </div>
        </div>
        
        <!-- Слайд 3 -->
        <div class="testimonial-slide" data-index="2">
          <div class="testimonial-content">
            <img src="v2_373.png" alt="Logo 3" class="testimonial-logo" />
            <h3>Профессионалы своего дела! Рекомендую!</h3>
            <p>Петрова Мария, CEO компании "ТехноЛогик"</p>
          </div>
        </div>
      </div>
      
      <!-- Карточка с контентом и навигацией (единая для всех слайдов) -->
      <div class="testimonial-card">
        <div class="testimonial-card-content">
          <!-- Здесь будет динамически меняться контент из активного слайда -->
        </div>
        <div class="testimonial-divider"></div>
        <div class="testimonial-nav">
          <button class="nav-arrow prev">
            <img src="arrow-left.svg" alt="Предыдущий отзыв" />
          </button>
          <span class="slide-counter">01 / 03</span>
          <button class="nav-arrow next">
            <img src="arrow-right.svg" alt="Следующий отзыв" />
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="partners" class="partners-section">
  <div class="container">
    <h2 class="partners-title">С нами работают</h2>
    <p class="partners-subtitle">
      Десятки компаний доверяют нам самое ценное, что у них есть в интернете –
      свои сайты. Мы делаем всё, чтобы наше сотрудничество было долгим.
    </p>
  </div>
  
  <!-- Первая бегущая строка -->
  <div class="partners-row-wrapper">
    <div class="partners-row">
      <div class="partner-card">
        <img src="v2_326.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_329.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_332.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_335.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_338.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_342.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_345.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_348.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_326.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_329.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_332.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_335.png" alt="Partner logo" />
      </div>
    </div>
  </div>
  
  <!-- Вторая бегущая строка (движется в обратном направлении) -->
  <div class="partners-row-wrapper reverse">
    <div class="partners-row">
      <div class="partner-card">
        <img src="v2_338.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_342.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_345.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_348.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_326.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_329.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_332.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_335.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_338.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_342.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_345.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_348.png" alt="Partner logo" />
      </div>
    </div>
  </div>
  
  <!-- Третья бегущая строка -->
  <div class="partners-row-wrapper">
    <div class="partners-row">
      <div class="partner-card">
        <img src="v2_345.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_348.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_326.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_329.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_332.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_335.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_338.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_342.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_345.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_348.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_326.png" alt="Partner logo" />
      </div>
      <div class="partner-card">
        <img src="v2_329.png" alt="Partner logo" />
      </div>
    </div>
  </div>
  
  <div class="container">
    <div class="partners-footer">
      <button class="btn btn-primary">Все кейсы</button>
    </div>
  </div>
</section>

<footer id="footer" class="footer-section">
  <div class="footer-bg"></div>
  <div class="footer-deco-1">
    <!-- merged image -->
    <!-- <img src="${ASSET_PATH}/2_214.svg" alt="decoration" />
    <img src="${ASSET_PATH}/2_231.svg" alt="decoration" /> -->
  </div>
  <div class="footer-deco-2">
    <!-- merged image -->
    <!-- <img src="${ASSET_PATH}/2_250.svg" alt="decoration" />
    <img src="${ASSET_PATH}/2_267.svg" alt="decoration" /> -->
  </div>
  <div class="container">
    <div class="footer-main">
      <div class="footer-contact-info">
        <h2>Оставить заявку на поддержку сайта</h2>
        <p>
          Срочно нужна поддержка сайта? Ваша команда не успевает справиться
          самостоятельно или предыдущий подрядчик не справился с работой? Тогда
          вам точно к нам! Просто оставьте заявку и наш менеджер с вами
          свяжется!
        </p>
        <div class="contact-details">
          <a href="tel:88002222673" class="contact-item">
            <img src="phone-line (2).svg" alt="phone icon" />
            <span>8 800 222-26-73</span>
          </a>
          <a href="mailto:info@drupal-coder.ru" class="contact-item">
            <img src="mail.svg" alt="mail icon" />
            <span>info@drupal-coder.ru</span>
          </a>
        </div>
      </div>
      
      <!-- Форма с атрибутами для Formcarry -->
     <!-- Обновленный HTML формы -->
<form 
  id="supportForm" 
  class="footer-form" 
  action="https://formcarry.com/s/2LYjmA9wtHj" 
  method="POST"
  accept-charset="UTF-8"
>
  <!-- Скрытое поле для защиты от спама -->
  <input type="hidden" name="_gotcha" style="display:none">
  
  <!-- Поле для редиректа после успешной отправки (опционально) -->
  <input type="hidden" name="_redirect" value="#thank-you-message">
  
  <div class="form-group">
    <input type="text" id="name" name="name" required />
    <label for="name">Ваше имя</label>
  </div>
  <div class="form-group">
    <input type="tel" id="phone" name="phone" required />
    <label for="phone">Телефон</label>
  </div>
  <div class="form-group">
    <input type="email" id="email" name="email" required />
    <label for="email">E-mail</label>
  </div>
  <div class="form-group">
    <textarea id="comment" name="comment" rows="4"></textarea>
    <label for="comment">Ваш комментарий</label>
  </div>
  <div class="form-checkbox">
    <input type="checkbox" id="agreement" name="agreement" required />
    <label for="agreement">
      <span class="custom-checkbox"></span>
      Отправляя заявку, я даю согласие на обработку своих персональных данных
    </label>
  </div>
  <button type="submit" class="btn btn-primary" id="submitBtn">
    <span class="btn-text">Оставить заявку!</span>
    <span class="btn-loader" style="display: none;">Отправка...</span>
  </button>
  
  <!-- Сообщения об успехе/ошибке -->
  <div id="form-messages" class="form-messages" style="display: none;"></div>
</form>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="container">
      <hr class="footer-divider" />
      <div class="footer-copyright">
        <p>Проект ООО «Инитлаб», Краснодар, Россия.</p>
        <p>
          Drupal является зарегистрированной торговой маркой Dries Buytaert.
        </p>
      </div>
    </div>
  </div>
</footer>
<script src="form_scr1.js"></script>
<script src="testimonial_js.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Элементы для мобильного меню
  const hamburger = document.querySelector('.hamburger');
  const mobileMenu = document.querySelector('.mobile-menu-overlay');
  const mobileMenuClose = document.querySelector('.mobile-menu-close');
  const mobileLangSwitcher = document.querySelector('.mobile-lang-switcher');
  const mobileLangCurrent = document.querySelector('.mobile-lang-current');
  
  // Функция для закрытия мобильного меню
  function closeMobileMenu() {
    mobileMenu.classList.remove('active');
    hamburger.classList.remove('active');
    document.body.style.overflow = '';
  }
  
  // Открытие/закрытие мобильного меню
  hamburger.addEventListener('click', function() {
    mobileMenu.classList.add('active');
    hamburger.classList.add('active');
    document.body.style.overflow = 'hidden';
  });
  
  mobileMenuClose.addEventListener('click', closeMobileMenu);
  
  // Закрытие меню при клике вне его
  mobileMenu.addEventListener('click', function(e) {
    if (e.target === mobileMenu) {
      closeMobileMenu();
    }
  });
  
  // Закрытие меню при нажатии Esc
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeMobileMenu();
    }
  });
  
  // Выпадающее меню языков на мобильных
  mobileLangCurrent.addEventListener('click', function() {
    mobileLangSwitcher.classList.toggle('active');
  });
  
  // ЗАКРЫТИЕ МЕНЮ ПРИ КЛИКЕ НА НАВИГАЦИОННЫЕ ССЫЛКИ
  // Находим все ссылки в мобильном меню
  const mobileNavLinks = document.querySelectorAll('.mobile-nav a');
  
  // Добавляем обработчик для каждой ссылки
  mobileNavLinks.forEach(link => {
    link.addEventListener('click', function(e) {
      // Закрываем меню
      closeMobileMenu();
      
      // Дополнительно: закрываем языковое меню, если оно открыто
      mobileLangSwitcher.classList.remove('active');
      
      // Обработка якорных ссылок (прокрутка происходит автоматически)
      // Можно добавить небольшую задержку для плавного закрытия меню перед скроллом
      const href = this.getAttribute('href');
      
      // Если ссылка ведет на якорь на этой же странице
      if (href.startsWith('#')) {
        // Добавляем небольшую задержку для плавного закрытия меню
        setTimeout(() => {
          const targetId = href.substring(1);
          const targetElement = document.getElementById(targetId);
          
          if (targetElement) {
            // Плавная прокрутка к элементу
            targetElement.scrollIntoView({
              behavior: 'smooth',
              block: 'start'
            });
          }
        }, 300); // Задержка для закрытия меню перед скроллом
      }
    });
  });
  
  // Также добавляем обработчик для ссылки телефона (если нужно)
  const mobilePhoneLink = document.querySelector('.mobile-phone-number');
  if (mobilePhoneLink) {
    mobilePhoneLink.addEventListener('click', closeMobileMenu);
  }
  
  // Выбор языка (для всех меню)
  const langLinks = document.querySelectorAll('.lang-dropdown a, .mobile-lang-dropdown a');
  
  langLinks.forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const lang = this.getAttribute('data-lang');
      
      // Обновляем текущий язык
      document.querySelector('.lang-current span').textContent = lang.toUpperCase();
      
      // Для мобильной версии
      const langNames = {
        'en': 'English',
        'de': 'Deutsch',
        'fr': 'Français',
        'es': 'Español',
        'zh': '中文',
        'ru': 'Русский'
      };
      
      document.querySelector('.mobile-lang-current span').textContent = 
        `Язык: ${langNames[lang] || 'Русский'} (${lang.toUpperCase()})`;
      
      // Закрываем выпадающие меню
      mobileLangSwitcher.classList.remove('active');
      closeMobileMenu(); // Используем общую функцию
      
      // Здесь можно добавить логику смены языка на сайте
      console.log('Выбран язык:', lang);
    });
  });
  
  // Закрытие языкового меню при клике вне его на десктопе
  document.addEventListener('click', function(e) {
    const langSwitcher = document.querySelector('.lang-switcher');
    if (!langSwitcher.contains(e.target)) {
      // Меню закрывается автоматически при уходе курсора благодаря CSS :hover
    }
  });
});
</script>
<script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('anketa-form');
            if (!form) return;
            const isAuthenticated = <?= json_encode($is_authenticated) ?>;
            const userId = <?= json_encode($current_user_id) ?>;
            form.addEventListener('submit', async function(e) {
                if (window.fetch) e.preventDefault();
                else return;

                const formData = new FormData(form);
                let data = {};
                for (let [key, value] of formData.entries()) {
                    if (key.endsWith('[]')) {
                        key = key.slice(0, -2);
                        if (!data[key]) data[key] = [];
                        data[key].push(value);
                    } else {
                        data[key] = value;
                    }
                }

                let method = 'POST';
                let url = 'api.php/application';
                if (isAuthenticated && userId) {
                    method = 'PUT';
                    url = `api.php/application/${userId}`;
                }

                try {
                    const response = await fetch(url, {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data)
                    });

                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        const text = await response.text();
                        throw new Error('Сервер вернул не JSON: ' + text.substring(0, 100));
                    }

                    const result = await response.json();
                    if (response.ok) {
                        if (method === 'POST' && result.login) {
                            alert(`Анкета сохранена!\nЛогин: ${result.login}\nПароль: ${result.password}\nСсылка: ${result.profile_url}`);
                        } else {
                            alert('Данные обновлены!');
                        }
                        window.location.reload();
                    } else {
                        let errorMsg = 'Ошибка:\n';
                        if (result.errors) {
                            for (let field in result.errors) {
                                errorMsg += `${field}: ${result.errors[field]}\n`;
                            }
                        } else {
                            errorMsg += result.error || 'Неизвестная ошибка';
                        }
                        alert(errorMsg);
                    }
                } catch (err) {
                    alert('Ошибка сети: ' + err.message);
                }
            });
        });
    </script>
</body>
</html>