<?php

session_start();

class ShoppingCart {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    // Single function to get product info, following DRY principle
    private function getProduct($productId) {
        $query = $this->db->prepare("SELECT * FROM products WHERE id = ?");
        $query->bind_param("i", $productId);
        $query->execute();
        $result = $query->get_result();
        return $result->fetch_assoc();
    }
    
    // Get multiple products at once to fix performance issue
    private function getMultipleProducts($productIds) {
        if (empty($productIds)) {
            return array();
        }
        
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $query = $this->db->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
        
        $types = str_repeat('i', count($productIds));
        $query->bind_param($types, ...$productIds);
        $query->execute();
        
        $result = $query->get_result();
        $products = array();
        
        while ($row = $result->fetch_assoc()) {
            $products[$row['id']] = $row;
        }
        
        return $products;
    }
    
    // Separate stock validation function, following Single Responsibility Principle
    private function isInStock($productId, $quantity) {
        $product = $this->getProduct($productId);
        return $product && $product['stock'] >= $quantity;
    }
    
    // Add item to cart with input validation
    public function addToCart($productId, $quantity) {
        if (!filter_var($productId, FILTER_VALIDATE_INT) || !filter_var($quantity, FILTER_VALIDATE_INT) || $quantity <= 0) {
            return false;
        }
        
        if (!$this->isInStock($productId, $quantity)) {
            return false;
        }
        
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = array();
        }
        
        if (isset($_SESSION['cart'][$productId])) {
            $_SESSION['cart'][$productId] += $quantity;
        } else {
            $_SESSION['cart'][$productId] = $quantity;
        }
        
        return true;
    }
    
    // Calculate total price using batch query for performance
    public function getTotal() {
        $total = 0;
        
        if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
            return $total;
        }
        
        $productIds = array_keys($_SESSION['cart']);
        $products = $this->getMultipleProducts($productIds);
        
        foreach ($_SESSION['cart'] as $productId => $quantity) {
            if (isset($products[$productId])) {
                $total += $products[$productId]['price'] * $quantity;
            }
        }
        
        return $total;
    }
    
    // Apply discount code with proper validation
    public function applyDiscount($code) {
        $code = trim($code);
        if (empty($code)) {
            return false;
        }
        
        $query = $this->db->prepare("SELECT * FROM discount_codes WHERE code = ? AND expires > CURDATE()");
        $query->bind_param("s", $code);
        $query->execute();
        $result = $query->get_result();
        $discount = $result->fetch_assoc();
        
        if ($discount) {
            $_SESSION['discount'] = $discount['percentage'];
            return true;
        }
        
        return false;
    }
    
    // Get final total with discount
    public function getFinalTotal() {
        $total = $this->getTotal();
        
        if (isset($_SESSION['discount'])) {
            $discountAmount = $total * ($_SESSION['discount'] / 100);
            $total = $total - $discountAmount;
        }
        
        return round($total, 2);
    }
    
    // Process checkout with transaction to prevent race conditions
    public function checkout($userId, $paymentMethod) {
        if (!filter_var($userId, FILTER_VALIDATE_INT) || empty($paymentMethod)) {
            return false;
        }
        
        $total = $this->getFinalTotal();
        if ($total <= 0) {
            return false;
        }
        
        $this->db->autocommit(false);
        
        try {
            $productIds = array_keys($_SESSION['cart']);
            $products = $this->getMultipleProducts($productIds);
            
            foreach ($_SESSION['cart'] as $productId => $quantity) {
                if (!isset($products[$productId])) {
                    throw new Exception("Product not found");
                }
                
                $query = $this->db->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
                $query->bind_param("iii", $quantity, $productId, $quantity);
                
                if (!$query->execute() || $query->affected_rows == 0) {
                    throw new Exception("Insufficient stock for product ID: " . $productId);
                }
            }
            
            $query = $this->db->prepare("INSERT INTO orders (user_id, total, payment_method, status) VALUES (?, ?, ?, 'pending')");
            $query->bind_param("ids", $userId, $total, $paymentMethod);
            
            if (!$query->execute()) {
                throw new Exception("Order creation failed");
            }
            
            $orderId = $this->db->insert_id;
            
            foreach ($_SESSION['cart'] as $productId => $quantity) {
                $price = $products[$productId]['price'];
                
                $query = $this->db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                $query->bind_param("iiid", $orderId, $productId, $quantity, $price);
                
                if (!$query->execute()) {
                    throw new Exception("Failed to add order item");
                }
            }
            
            $this->db->commit();
            
            unset($_SESSION['cart']);
            unset($_SESSION['discount']);
            
            return $orderId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        } finally {
            $this->db->autocommit(true);
        }
    }
    
    // Get cart contents using batch query for performance
    public function getCartContents() {
        $contents = array();
        
        if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
            return $contents;
        }
        
        $productIds = array_keys($_SESSION['cart']);
        $products = $this->getMultipleProducts($productIds);
        
        foreach ($_SESSION['cart'] as $productId => $quantity) {
            if (isset($products[$productId])) {
                $product = $products[$productId];
                $contents[] = array(
                    'product' => $product,
                    'quantity' => $quantity,
                    'subtotal' => $product['price'] * $quantity
                );
            }
        }
        
        return $contents;
    }
}

// Usage example
$db = new mysqli('localhost', 'user', 'password', 'ecommerce');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

if (isset($_POST['action'])) {
    $cart = new ShoppingCart($db);
    
    switch ($_POST['action']) {
        case 'add':
            $result = $cart->addToCart($_POST['product_id'], $_POST['quantity']);
            echo $result ? 'Added to cart' : 'Failed to add';
            break;
            
        case 'checkout':
            $orderId = $cart->checkout($_SESSION['user_id'], $_POST['payment_method']);
            echo $orderId ? "Order created: " . $orderId : "Checkout failed";
            break;
            
        case 'apply_discount':
            $result = $cart->applyDiscount($_POST['discount_code']);
            echo $result ? 'Discount applied' : 'Invalid discount code';
            break;
    }
}

?>
