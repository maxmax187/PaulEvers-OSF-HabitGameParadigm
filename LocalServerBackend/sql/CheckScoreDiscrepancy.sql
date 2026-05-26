WITH computed_scores AS (
    SELECT
        participantEmail,
        SUM(CASE
            -- Training rounds
            WHEN phase = 'Training' AND finished = 0                                        THEN 0
            WHEN phase = 'Training' AND finished = 1 AND pickedUpCoin = 0                   THEN 2
            WHEN phase = 'Training' AND finished = 1 AND pickedUpCoin = 1 AND coinIdentity = 0 THEN 3
            WHEN phase = 'Training' AND finished = 1 AND pickedUpCoin = 1 AND coinIdentity = 1 THEN 4
            -- Test rounds
            WHEN phase = 'Test' AND finished = 0                                            THEN 0
            WHEN phase = 'Test' AND finished = 1 AND pickedUpCoin = 0                       THEN 2
            WHEN phase = 'Test' AND finished = 1 AND pickedUpCoin = 1 AND coinIdentity = 0  THEN 3
            WHEN phase = 'Test' AND finished = 1 AND pickedUpCoin = 1 AND coinIdentity = 1  THEN 2
            ELSE 0
        END) AS recomputed_score
    FROM r
    WHERE phase IN ('Training', 'Test')
    GROUP BY participantEmail
)
SELECT
    p.email,
    p.totalScore         AS recorded_score,
    c.recomputed_score,
    p.totalScore - c.recomputed_score AS discrepancy
FROM p
JOIN computed_scores c ON p.email = c.participantEmail
ORDER BY discrepancy DESC;