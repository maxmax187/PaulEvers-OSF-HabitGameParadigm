DELETE FROM h
WHERE id NOT IN (
    SELECT MIN(id)
    FROM h
    GROUP BY participantEmail, day, srbai1, srbai2, srbai3, srbai4
)
AND (participantEmail, day, srbai1, srbai2, srbai3, srbai4) IN (
    SELECT participantEmail, day, srbai1, srbai2, srbai3, srbai4
    FROM h
    GROUP BY participantEmail, day, srbai1, srbai2, srbai3, srbai4
    HAVING COUNT(*) > 1
);