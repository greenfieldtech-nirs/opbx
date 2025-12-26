/**
 * Password Generator Utility
 *
 * Generates cryptographically secure random passwords
 */

/**
 * Generate a strong password with specified length
 *
 * Password requirements:
 * - At least one uppercase letter
 * - At least one lowercase letter
 * - At least one number
 * - At least one symbol
 *
 * @param length - The desired password length (default: 16, min: 12, max: 32)
 * @returns A cryptographically secure random password
 */
export function generateStrongPassword(length: number = 16): string {
  // Ensure length is within reasonable bounds
  const passwordLength = Math.max(12, Math.min(32, length));

  // Character sets
  const uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  const lowercase = 'abcdefghijklmnopqrstuvwxyz';
  const numbers = '0123456789';
  const symbols = '!@#$%^&*()_+-=[]{}|;:,.<>?';

  const allChars = uppercase + lowercase + numbers + symbols;

  // Use crypto.getRandomValues for cryptographically secure random numbers
  const getRandomChar = (chars: string): string => {
    const randomIndex = crypto.getRandomValues(new Uint32Array(1))[0] % chars.length;
    return chars[randomIndex];
  };

  // Ensure at least one character from each required set
  let password = '';
  password += getRandomChar(uppercase);
  password += getRandomChar(lowercase);
  password += getRandomChar(numbers);
  password += getRandomChar(symbols);

  // Fill the rest with random characters from all sets
  for (let i = password.length; i < passwordLength; i++) {
    password += getRandomChar(allChars);
  }

  // Shuffle the password to avoid predictable patterns
  // Convert to array, shuffle using Fisher-Yates algorithm, then join
  const passwordArray = password.split('');
  for (let i = passwordArray.length - 1; i > 0; i--) {
    const j = crypto.getRandomValues(new Uint32Array(1))[0] % (i + 1);
    [passwordArray[i], passwordArray[j]] = [passwordArray[j], passwordArray[i]];
  }

  return passwordArray.join('');
}

/**
 * Validate if a password meets strength requirements
 *
 * @param password - The password to validate
 * @returns Object with validation result and error message
 */
export function validatePasswordStrength(password: string): {
  valid: boolean;
  message?: string;
} {
  if (password.length < 8) {
    return { valid: false, message: 'Password must be at least 8 characters long' };
  }

  if (!/[A-Z]/.test(password)) {
    return { valid: false, message: 'Password must contain at least one uppercase letter' };
  }

  if (!/[a-z]/.test(password)) {
    return { valid: false, message: 'Password must contain at least one lowercase letter' };
  }

  if (!/[0-9]/.test(password)) {
    return { valid: false, message: 'Password must contain at least one number' };
  }

  if (!/[!@#$%^&*()_+\-=[\]{}|;:,.<>?]/.test(password)) {
    return { valid: false, message: 'Password must contain at least one symbol' };
  }

  return { valid: true };
}
