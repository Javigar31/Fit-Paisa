
const fs = require('fs');
const path = require('path');

function search(dir) {
    const files = fs.readdirSync(dir);
    for (const file of files) {
        const fullPath = path.join(dir, file);
        if (fs.statSync(fullPath).isDirectory()) {
            if (file !== 'node_modules' && file !== '.git') {
                search(fullPath);
            }
        } else {
            const content = fs.readFileSync(fullPath, 'utf8');
            if (content.includes('3877') || content.includes('180') || content.includes('587')) {
                console.log(`FOUND in ${fullPath}`);
                // Print surrounding context
                const lines = content.split('\n');
                lines.forEach((line, i) => {
                    if (line.includes('3877') || line.includes('180') || line.includes('587')) {
                        console.log(`  Line ${i + 1}: ${line.trim()}`);
                    }
                });
            }
        }
    }
}

search('.');
