SELECT 
    participantEmail,
    day,
    COUNT(*) AS round_count,
    MIN(date) AS first_round,
    MAX(date) AS last_round,
    TIMESTAMPDIFF(MINUTE, MIN(date), MAX(date)) AS session_duration_mins
FROM r
GROUP BY participantEmail, day
ORDER BY participantEmail, day;