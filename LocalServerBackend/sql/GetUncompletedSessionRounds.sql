WITH practice_starts AS (
    SELECT
        participantEmail,
        date AS practice_start,
        ROW_NUMBER() OVER (PARTITION BY participantEmail ORDER BY date DESC) AS rn
    FROM r
    WHERE day = 1 AND phase = 'Practice' AND round = 1
)
SELECT r.*
FROM r
JOIN practice_starts p 
    ON r.participantEmail = p.participantEmail 
    AND p.rn = 1
WHERE r.day = 1
AND r.date < p.practice_start
AND r.participantEmail IN (
    SELECT participantEmail FROM r
    WHERE day = 1
    GROUP BY participantEmail
    HAVING COUNT(*) != 106
);