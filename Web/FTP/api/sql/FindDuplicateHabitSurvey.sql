SELECT 
    participantEmail,
    day,
    srbai1,
    srbai2,
    srbai3,
    srbai4,
    COUNT(*) AS occurrences
FROM h
GROUP BY participantEmail, day, srbai1, srbai2, srbai3, srbai4
HAVING occurrences > 1
ORDER BY participantEmail, day;