document.addEventListener('DOMContentLoaded', () => {
    // 1. Validar que solo se puedan escribir números en campos con inputmode="numeric" o type="number"
    // Esto aplicará a todos los campos actuales y futuros en el DOM, mediante delegación de eventos si es necesario,
    // pero como el DOM puede cambiar (elementos inyectados por JS), lo mejor es usar delegación de eventos en el body
    // para los eventos 'input'
    
    document.body.addEventListener('input', function(e) {
        const target = e.target;
        
        // Validación de números enteros
        // Se aplica a campos type="number", inputmode="numeric" o aquellos cuyo ID o name contenga 'telefono' o 'referencia'
        const isNumeric = target.matches('input[type="number"], input[inputmode="numeric"]') ||
                          (target.id && (target.id.toLowerCase().includes('telefono') || target.id.toLowerCase().includes('referencia') || target.id.toLowerCase().includes('cuenta') || target.id.toLowerCase().includes('documento') || target.id.toLowerCase().includes('cedula'))) ||
                          (target.name && (target.name.toLowerCase().includes('telefono') || target.name.toLowerCase().includes('referencia') || target.name.toLowerCase().includes('cuenta') || target.name.toLowerCase().includes('documento') || target.name.toLowerCase().includes('cedula')));
                          
        if (isNumeric) {
            // Límite de longitud y formato
            const id = (target.id || '').toLowerCase();
            const name = (target.name || '').toLowerCase();
            
            if (id.includes('cuenta') || name.includes('cuenta')) {
                let raw = target.value.replace(/[^0-9]/g, '').slice(0, 20);
                let formatted = '';
                if (raw.length > 0) formatted += raw.slice(0, 4);
                if (raw.length > 4) formatted += '-' + raw.slice(4, 8);
                if (raw.length > 8) formatted += '-' + raw.slice(8, 10);
                if (raw.length > 10) formatted += '-' + raw.slice(10, 20);
                target.value = formatted;
            } else if (id.includes('documento') || name.includes('documento') || id.includes('cedula') || name.includes('cedula')) {
                let raw = target.value.replace(/[^0-9]/g, '').slice(0, 9);
                if (raw) {
                    target.value = raw.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
                } else {
                    target.value = '';
                }
            } else {
                target.value = target.value.replace(/[^0-9]/g, '');
                if (id.includes('telefono') || name.includes('telefono')) {
                    if (target.value.length > 7) target.value = target.value.slice(0, 7);
                } else if (id.includes('referencia') || name.includes('referencia')) {
                    if (target.value.length > 22) target.value = target.value.slice(0, 22);
                }
            }
        }
        
        // Validación de números decimales (precios, tasas)
        if (target.matches('input[inputmode="decimal"]')) {
            target.value = target.value.replace(/[^0-9.]/g, '');
            // Evitar más de un punto decimal
            if ((target.value.match(/\./g) || []).length > 1) {
                target.value = target.value.substring(0, target.value.lastIndexOf('.'));
            }
        }
        
        // 2. Al inicio de cada escritura en campos de texto, comenzar con mayúscula
        if (target.matches('input[type="text"], input[type="search"], textarea')) {
            // Excluir campos donde no queremos auto-mayúscula: emails, usuarios, contraseñas
            const isEmailOrUser = target.name === 'email' || target.id === 'email' || 
                                  target.name === 'usuario' || target.id === 'usuario' ||
                                  target.type === 'password' || target.type === 'email' ||
                                  target.name === 'password';
            
            if (!isEmailOrUser && target.value.length > 0) {
                target.value = target.value.charAt(0).toUpperCase() + target.value.slice(1);
            }
        }
    });

    // 3. Otra validación: evitar que dejen solo espacios en blanco y hacer trim al desenfocar (blur)
    // El evento 'blur' no burbujea igual que 'input', pero podemos usar 'focusout' que sí burbujea
    document.body.addEventListener('focusout', function(e) {
        const target = e.target;
        if (target.matches('input[type="text"], input[type="search"], textarea, input[type="email"]')) {
            // Evitar modificar contraseñas
            if (target.type !== 'password' && target.value) {
                target.value = target.value.trim();
            }
        }
    });
});
