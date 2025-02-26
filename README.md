Monitoring Status Page

A simple monitoring status page built with PHP and MySQL. This project allows you to create customizable status pages to display service uptime, incidents, and maintenance history. It includes an installation script to set up the necessary database and tables automatically.
Features

    Customizable Status Pages: Create and display status pages based on a unique UUID.
    Automated Database Setup: An installation script (install.php) sets up the MySQL database and all required tables.
    Responsive Design: Modern, responsive styling using CSS with options for custom CSS per status page.
    Easy Integration: Simple structure with separate files for the database connection, installation, and status page display.

Requirements

    PHP: Version 7.4 or later (tested on PHP 8.2)
    MySQL/MariaDB: For the database
    Web Server: Apache, Nginx, or any compatible server

Installation

    Clone the Repository:

git clone https://github.com/finn1476/Status-Page
cd your-repo

Configure the Database Connection:

Open the db.php file and adjust the database settings as needed:

    <?php
    // db.php – Database connection for the "monitoring" database
    $dbHost = 'localhost';
    $dbName = 'monitoring';
    $dbUser = 'root';
    $dbPass = '';
    // ...
    ?>

    Run the Installation Script:

    Access install.php through your browser (e.g., http://localhost/your-repo/install.php). This script will:
        Create the monitoring database if it does not exist.
        Create all required tables and set up foreign key constraints.

    Note: For security reasons, delete or restrict access to install.php after the installation is complete.

    Add the check.php into a crontab.
    Restrict access to the check.php so it cant be viewed from the Web Browser

Usage

    Viewing a Status Page:

    To view a status page, navigate to index.php with a valid UUID as a GET parameter:

    http://yourdomain.com/index2.php?uuid=YOUR_STATUSPAGE_UUID

    If the UUID is missing or invalid, the system displays an error page with an appropriate message.

    Customizing the Page:

    You can add custom CSS directly through the database for each status page, allowing further personalization of the layout and design.


Contributing

Contributions are welcome! If you have ideas for improvements or encounter any issues, please open an issue or submit a pull request.
License

![image](https://github.com/user-attachments/assets/ff71cb7e-247d-48f3-ad86-5f3a213a3bee)

![image](https://github.com/user-attachments/assets/1da79209-a03a-4940-81d1-76212a115050)
