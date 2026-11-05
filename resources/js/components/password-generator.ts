// Palavra-passe temporária: 10 caracteres sem letras parecidas (0/O, 1/l/I),
// para poder ser lida em voz alta ou escrita sem enganos.
export function generatePassword(): string {
    const alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    const bytes = new Uint32Array(10);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => alphabet[b % alphabet.length]).join('');
}
