-- The correct run starts at the last time a participant began a new Practice sequence
WITH practice_starts AS (
    SELECT
        participantEmail,
        date AS practice_start,
        ROW_NUMBER() OVER (PARTITION BY participantEmail ORDER BY date DESC) AS rn
    FROM r
    WHERE day = 1 AND phase = 'Practice' AND round = 1
)
SELECT
    r.participantEmail,
    r.phase,
    r.round,
    r.date,
    p.practice_start AS correct_run_start,
    CASE WHEN r.date < p.practice_start THEN 'UNFINISHED' ELSE 'complete' END AS status
FROM r
JOIN practice_starts p 
    ON r.participantEmail = p.participantEmail 
    AND p.rn = 1
WHERE r.day = 1
AND r.participantEmail IN (
    SELECT participantEmail FROM r
    WHERE day = 1
    GROUP BY participantEmail
    HAVING COUNT(*) != 106
)
ORDER BY r.participantEmail, r.date;