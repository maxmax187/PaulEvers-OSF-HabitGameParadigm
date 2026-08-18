DELETE FROM r
WHERE day = 1
AND date < (
    SELECT practice_start FROM (
        SELECT
            participantEmail,
            date AS practice_start,
            ROW_NUMBER() OVER (PARTITION BY participantEmail ORDER BY date DESC) AS rn
        FROM r
        WHERE day = 1 AND phase = 'Practice' AND round = 1
    ) AS ps
    WHERE ps.participantEmail = r.participantEmail
    AND ps.rn = 1
)
AND participantEmail IN (
    SELECT participantEmail FROM (
        SELECT participantEmail
        FROM r
        WHERE day = 1
        GROUP BY participantEmail
        HAVING COUNT(*) != 106
    ) AS affected
);