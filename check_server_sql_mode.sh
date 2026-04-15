#!/bin/bash
# Script para verificar sql_mode en el servidor

echo "=== SERVIDOR MySQL SQL Mode Status ==="
echo "Server: 172.20.3.52"
echo "Database: dbpbstud"
echo ""

mysql -u pbsuser -pPb5tud10 --host=localhost dbpbstud -e "SELECT @@sql_mode as sql_mode;" 2>/dev/null | while read line; do
    if [[ $line == *"ONLY_FULL_GROUP_BY"* ]]; then
        echo "❌ ONLY_FULL_GROUP_BY is ENABLED (Strict Mode)"
        echo "⚠️  ERROR: The ReservationRepository GROUP BY queries WILL FAIL in production!"
    elif [[ $line == "sql_mode" ]] || [[ $line == "" ]]; then
        echo "$line"
    else
        echo "Current sql_mode: $line"
        echo ""
        echo "✅ ONLY_FULL_GROUP_BY is DISABLED (Permissive Mode)"
        echo "Server configuration matches specification requirements."
    fi
done
