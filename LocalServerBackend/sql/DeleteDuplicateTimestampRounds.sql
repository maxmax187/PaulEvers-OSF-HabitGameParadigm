DELETE FROM r
WHERE id NOT IN (
    SELECT MIN(id)
    FROM r
    GROUP BY participantEmail, date
)
AND (participantEmail, date) IN (
    SELECT participantEmail, date
    FROM r
    GROUP BY participantEmail, date
    HAVING COUNT(*) > 1
);