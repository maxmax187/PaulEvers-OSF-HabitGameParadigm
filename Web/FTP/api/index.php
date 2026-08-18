<?php
header('Content-Type: application/json');

// load environment variables
require_once __DIR__ . '/env.php';
loadEnv();

// Database connection using environment variables
// TODO Max: should we change this?? or are these simply default values
$db = new mysqli(
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_USER') ?: 'pevers',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_NAME') ?: 'pevers',
    getenv('DB_PORT') ?: 3306
);


if ($db->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]));
}

// Increase POST size limit
ini_set('post_max_size', '5M');

// Enable error reporting
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', 'php-errors.log');

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Extract the base path from the request
$base_path = getenv('BASE_PATH') ?: '/1af8d72c/api';
$actual_path = str_replace($base_path, '', $path);

// Route handling
switch ($actual_path) {
    case '/addParticipant':
        handleAddParticipant($db);
        break;
    case '/getParticipant':
        handleGetParticipant($db);
        break;
    case '/addRoundWithTransaction':
        handleAddRoundWithTransaction($db);
        break;
    case '/bulkInsert':
        handleBulkInsert($db);
        break;
    case '/addHabitSurvey':
        handleAddHabitSurvey($db);
        break;
    case '/addPXISurvey':
        handleAddPXISurvey($db);
        break;
    case '/updateScore':
        handleUpdateScore($db);
        break;
    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
}

function handleAddParticipant($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $db->prepare("INSERT INTO participants (email, totalScore, characterSelect, trainingSeeds, testSeeds) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sisss", 
        $data['email'],
        $data['totalScore'],
        $data['characterSelect'],
        $data['trainingSeeds'],
        $data['testSeeds']
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'email' => $data['email']
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error'
        ]);
    }
    $stmt->close();
}

function handleAddRoundWithTransaction($db) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $round = $data['round'];
        $roundLogs = $data['roundLogs'];

        // Validation
        if (!$round || !$round['participantEmail']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required round data'
            ]);
            return;
        }

        if (!is_array($roundLogs) || empty($roundLogs)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing or invalid roundLogs data'
            ]);
            return;
        }

        // Start transaction
        $db->begin_transaction();

        try {
            // Insert round
            // OLD: $stmt = $db->prepare("INSERT INTO rounds (participantEmail, seed, round, didCoinSpawn, pickedUpCoin, finished, phase, date, totalDistance, distanceCoinSpawn, remainingTime, totalRoundsFinished, day, coinSpawnTime, coinPickupTime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            // OLD: $stmt->bind_param("siiiiissdddiidd",
            //     $round['participantEmail'],
            //     $round['seed'],
            //     $round['round'],
            //     $round['didCoinSpawn'],
            //     $round['pickedUpCoin'],
            //     $round['finished'],
            //     $round['phase'],
            //     $round['date'],
            //     $round['totalDistance'],
            //     $round['distanceCoinSpawn'],
            //     $round['remainingTime'],
            //     $round['totalRoundsFinished'],
            //     $round['day'],
            //     $round['coinSpawnTime'],
            //     $round['coinPickupTime']
            // );
            $stmt = $db->prepare("INSERT INTO rounds (participantEmail, seed, round, pickedUpCoin, finished, phase, date, remainingTime, totalRoundsFinished, day, thoughtBubbleTime, bufferDelay, coinPresentTime, playerChoiceTime, reactionTime, wentBackForCoin, coinIdentity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siiiissdiidddddii",
                $round['participantEmail'],
                $round['seed'],
                $round['round'],
                $round['pickedUpCoin'],
                $round['finished'],
                $round['phase'],
                $round['date'],
                $round['remainingTime'],
                $round['totalRoundsFinished'],
                $round['day'],
                $round['thoughtBubbleTime'],
                $round['bufferDelay'],
                $round['coinPresentTime'],
                $round['playerChoiceTime'],
                $round['reactionTime'],
                $round['wentBackForCoin'],
                $round['coinIdentity']
            );
            $stmt->execute();
            $roundId = $stmt->insert_id;
            $stmt->close();

            // Insert logs
            $stmt = $db->prepare("INSERT INTO roundLogs (roundId, t, d) VALUES (?, ?, ?)");
            foreach ($roundLogs as $log) {
                $stmt->bind_param("idd", $roundId, $log['t'], $log['d']);
                $stmt->execute();
            }
            $stmt->close();

            $db->commit();
            echo json_encode([
                'success' => true,
                'roundId' => $roundId
            ]);

        } catch (Exception $e) {
            $db->rollback();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error'
            ]);
        }
    } catch (Exception $e) {
        error_log("Error in handleAddRoundWithTransaction: " . $e->getMessage());
        die(json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]));
    }
}

function handleBulkInsert($db) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid data format'
        ]);
        return;
    }

    $stmt = $db->prepare("INSERT INTO players (name, score) VALUES (?, ?)");
    $rowsInserted = 0;

    foreach ($data as $row) {
        $stmt->bind_param("si", $row['name'], $row['score']);
        if ($stmt->execute()) {
            $rowsInserted++;
        }
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'rowsInserted' => $rowsInserted
    ]);
}

function handleGetParticipant($db) {
    // Get email from query parameter
    $email = isset($_GET['email']) ? $_GET['email'] : null;
    
    if (!$email) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email parameter is required'
        ]);
        return;
    }

    $stmt = $db->prepare("SELECT email, totalScore, characterSelect, trainingSeeds, testSeeds FROM participants WHERE email = ?");
    $stmt->bind_param("s", $email);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $participant = $result->fetch_assoc();
        
        if ($participant) {
            echo json_encode([
                'success' => true,
                'participant' => $participant
            ]);
        } else {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Participant not found'
            ]);
        }
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error'
        ]);
    }
    $stmt->close();
}

function handleAddHabitSurvey($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $db->prepare("INSERT INTO habitSurvey (participantEmail, day, srbai1, srbai2, srbai3, srbai4) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siiiii", 
        $data['participantEmail'],
        $data['day'],
        $data['srbai1'],
        $data['srbai2'],
        $data['srbai3'],
        $data['srbai4']
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'id' => $stmt->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error'
        ]);
    }
    $stmt->close();
}

function handleAddPXISurvey($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $db->prepare("INSERT INTO pxiSurvey (participantEmail, day, aa, ch, ec, gr, pf, aut, cur, imm, mas, mea, enj) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siiiiiiiiiiii", 
        $data['participantEmail'],
        $data['day'],
        $data['aa'],
        $data['ch'],
        $data['ec'],
        $data['gr'],
        $data['pf'],
        $data['aut'],
        $data['cur'],
        $data['imm'],
        $data['mas'],
        $data['mea'],
        $data['enj']
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'id' => $stmt->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error'
        ]);
    }
    $stmt->close();
}

function handleUpdateScore($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['email']) || !isset($data['totalScore'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing email or totalScore'
        ]);
        return;
    }

    $stmt = $db->prepare("UPDATE participants SET totalScore = ? WHERE email = ?");
    $stmt->bind_param("is", 
        $data['totalScore'],
        $data['email']
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Score updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error'
        ]);
    }
    $stmt->close();
}
?>
