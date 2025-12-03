<?php
// Updated web/index.php

// Fetch customers data
$customers = fetchCustomers();

// Fetch computers and filter only powered-on ones
$computers = fetchComputers();
$poweredOnComputers = array_filter($computers, function($computer) {
    return $computer['status'] === 'powered-on';
});

// Display powered-on computers table
if (!empty($poweredOnComputers)) {
    echo '<h2>Powered-On Computers</h2>';
    echo '<table>';
    foreach ($poweredOnComputers as $computer) {
        echo '<tr><td>' . htmlspecialchars($computer['name']) . '</td></tr>';
    }
    echo '</table>';
}

// Display customers table below computers
if (!empty($customers)) {
    echo '<h2>Customers</h2>';
    echo '<table>';
    foreach ($customers as $customer) {
        echo '<tr><td>' . htmlspecialchars($customer['name']) . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p>No customers found.</p>';
}
?>