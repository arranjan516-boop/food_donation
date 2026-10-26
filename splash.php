<?php
// splash.php
// This is the entry point of the application.
// It shows the logo for 3 seconds, then redirects to index.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to FoodConnect</title>
    <link rel="icon" href="assets/images/splash-logo.png">
    <style>
        body, html {
            margin: 0; padding: 0;
            width: 100%; height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            overflow: hidden;
        }

        .splash-container {
            text-align: center;
            animation: fadeIn 1s ease-in-out;
        }

        /* The Logo */
        .splash-logo {
            width: 300px;
            max-width: 80vw;
            height: auto;
            animation: zoomIn 1.5s ease-out forwards;
        }

        /* Optional loading bar */
        .loading-bar {
            width: 200px;
            height: 4px;
            background-color: #E8F5E9;
            border-radius: 4px;
            margin: 30px auto 0;
            overflow: hidden;
            position: relative;
        }

        .loading-bar::after {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 0%;
            background-color: #2E7D32;
            animation: loadBar 2.5s ease-in-out forwards;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes zoomIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        @keyframes loadBar {
            0% { width: 0%; }
            50% { width: 60%; }
            100% { width: 100%; }
        }
    </style>
    <!-- Auto-redirect after 3 seconds (3000ms) -->
    <meta http-equiv="refresh" content="3;url=index.php">
</head>
<body>

    <div class="splash-container">
        <!-- Update the src here if your image is .jpg -->
        <img src="assets/images/splash-logo.png" alt="FoodConnect Logo" class="splash-logo">
        
        <div class="loading-bar"></div>
    </div>

</body>
</html>
