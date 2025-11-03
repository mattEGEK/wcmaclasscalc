/**
 * Form Handler Module
 * Handles form validation and submission
 */

const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 MB in bytes
const ALLOWED_FILE_TYPES = {
    'dyno_chart': ['.pdf', '.doc', '.docx', '.jpg', '.jpeg', '.png'],
    'dyno_table': ['.pdf', '.doc', '.docx', '.jpg', '.jpeg', '.png'],
    'car_image': ['.jpg', '.jpeg', '.png']
};

/**
 * Validate email format
 * @param {string} email - Email address to validate
 * @returns {boolean} True if valid
 */
export function validateEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

/**
 * Validate year format (4 digits)
 * @param {string} year - Year to validate
 * @returns {boolean} True if valid
 */
export function validateYear(year) {
    const yearRegex = /^\d{4}$/;
    return yearRegex.test(year);
}

/**
 * Validate file type
 * @param {File} file - File object to validate
 * @param {string} fieldName - Name of the file input field
 * @returns {Object} Validation result with isValid and error message
 */
export function validateFileType(file, fieldName) {
    if (!file) {
        return { isValid: true, error: '' }; // File is optional
    }

    const allowedTypes = ALLOWED_FILE_TYPES[fieldName] || [];
    const fileName = file.name.toLowerCase();
    const fileExtension = fileName.substring(fileName.lastIndexOf('.'));

    if (!allowedTypes.includes(fileExtension)) {
        const typesStr = allowedTypes.join(', ');
        return {
            isValid: false,
            error: `Invalid file type. Allowed types: ${typesStr}`
        };
    }

    return { isValid: true, error: '' };
}

/**
 * Validate file size
 * @param {File} file - File object to validate
 * @returns {Object} Validation result with isValid and error message
 */
export function validateFileSize(file) {
    if (!file) {
        return { isValid: true, error: '' }; // File is optional
    }

    if (file.size > MAX_FILE_SIZE) {
        return {
            isValid: false,
            error: `File size exceeds 2 MB limit. Current size: ${(file.size / 1024 / 1024).toFixed(2)} MB`
        };
    }

    return { isValid: true, error: '' };
}

/**
 * Validate required field
 * @param {string} value - Field value
 * @param {string} fieldName - Name of the field
 * @returns {Object} Validation result with isValid and error message
 */
export function validateRequired(value, fieldName) {
    if (!value || value.trim() === '') {
        return {
            isValid: false,
            error: `${fieldName} is required`
        };
    }
    return { isValid: true, error: '' };
}

/**
 * Validate numeric field
 * @param {string|number} value - Field value
 * @param {string} fieldName - Name of the field
 * @param {boolean} required - Whether field is required
 * @returns {Object} Validation result with isValid and error message
 */
export function validateNumeric(value, fieldName, required = true) {
    if (!value || value === '') {
        if (required) {
            return {
                isValid: false,
                error: `${fieldName} is required`
            };
        }
        return { isValid: true, error: '' };
    }

    const numValue = parseFloat(value);
    if (isNaN(numValue) || numValue < 0) {
        return {
            isValid: false,
            error: `${fieldName} must be a valid positive number`
        };
    }

    return { isValid: true, error: '' };
}

/**
 * Show error message for a field
 * @param {string} fieldId - ID of the field
 * @param {string} message - Error message to display
 */
export function showFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    const errorElement = document.getElementById(`${fieldId}-error`);

    if (field) {
        field.classList.add('error');
    }

    if (errorElement) {
        errorElement.textContent = message;
        errorElement.classList.add('show');
    }
}

/**
 * Clear error message for a field
 * @param {string} fieldId - ID of the field
 */
export function clearFieldError(fieldId) {
    const field = document.getElementById(fieldId);
    const errorElement = document.getElementById(`${fieldId}-error`);

    if (field) {
        field.classList.remove('error');
    }

    if (errorElement) {
        errorElement.textContent = '';
        errorElement.classList.remove('show');
    }
}

/**
 * Validate entire form
 * @param {HTMLFormElement} form - Form element to validate
 * @returns {boolean} True if form is valid
 */
export function validateForm(form) {
    let isValid = true;

    // Validate required text fields
    const requiredFields = [
        { id: 'name', name: 'Name' },
        { id: 'email', name: 'Email' },
        { id: 'year', name: 'Year' },
        { id: 'make', name: 'Make' },
        { id: 'model', name: 'Model' }
    ];

    requiredFields.forEach(field => {
        const value = form.querySelector(`#${field.id}`).value;
        const validation = validateRequired(value, field.name);
        if (!validation.isValid) {
            showFieldError(field.id, validation.error);
            isValid = false;
        } else {
            clearFieldError(field.id);
        }
    });

    // Validate email format
    const email = form.querySelector('#email').value;
    if (email && !validateEmail(email)) {
        showFieldError('email', 'Please enter a valid email address');
        isValid = false;
    }

    // Validate year format
    const year = form.querySelector('#year').value;
    if (year && !validateYear(year)) {
        showFieldError('year', 'Year must be 4 digits');
        isValid = false;
    }

    // Validate numeric fields
    const numericFields = [
        { id: 'competition-weight', name: 'Competition Weight', required: true },
        { id: 'declared-hp', name: 'Declared HP', required: true },
        { id: 'dyno-hp', name: 'Dyno HP', required: false }
    ];

    numericFields.forEach(field => {
        const value = form.querySelector(`#${field.id}`).value;
        const validation = validateNumeric(value, field.name, field.required);
        if (!validation.isValid) {
            showFieldError(field.id, validation.error);
            isValid = false;
        } else {
            clearFieldError(field.id);
        }
    });

    // Validate file uploads
    const fileFields = [
        { id: 'dyno-chart', name: 'dyno_chart' },
        { id: 'dyno-table', name: 'dyno_table' },
        { id: 'car-image', name: 'car_image' }
    ];

    fileFields.forEach(field => {
        const fileInput = form.querySelector(`#${field.id}`);
        const file = fileInput.files[0];

        // Clear previous errors
        clearFieldError(field.id);

        if (file) {
            // Validate file type
            const typeValidation = validateFileType(file, field.name);
            if (!typeValidation.isValid) {
                showFieldError(field.id, typeValidation.error);
                isValid = false;
            }

            // Validate file size
            const sizeValidation = validateFileSize(file);
            if (!sizeValidation.isValid) {
                showFieldError(field.id, sizeValidation.error);
                isValid = false;
            }
        }
    });

    return isValid;
}

/**
 * Show form message (success or error)
 * @param {string} message - Message to display
 * @param {string} type - Message type ('success' or 'error')
 */
export function showFormMessage(message, type = 'success') {
    const messageElement = document.getElementById('form-messages');
    if (messageElement) {
        messageElement.textContent = message;
        messageElement.className = `form-messages show ${type}`;
        messageElement.setAttribute('role', 'alert');
        
        // Scroll to message
        messageElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Auto-hide after 5 seconds for success messages
        if (type === 'success') {
            setTimeout(() => {
                messageElement.classList.remove('show');
            }, 5000);
        }
    }
}

/**
 * Clear form message
 */
export function clearFormMessage() {
    const messageElement = document.getElementById('form-messages');
    if (messageElement) {
        messageElement.textContent = '';
        messageElement.className = 'form-messages';
    }
}

/**
 * Handle form submission
 * @param {HTMLFormElement} form - Form element to submit
 * @param {Function} onSubmitCallback - Callback function called before submission
 * @returns {Promise} Promise that resolves when submission is complete
 */
export async function handleFormSubmit(form, onSubmitCallback = null) {
    // Validate form
    if (!validateForm(form)) {
        showFormMessage('Please correct the errors in the form before submitting.', 'error');
        return Promise.reject(new Error('Form validation failed'));
    }

    // Call callback if provided (e.g., to collect calculation results)
    if (onSubmitCallback) {
        onSubmitCallback(form);
    }

    // Create FormData object
    const formData = new FormData(form);

    // Show loading state
    const submitButton = form.querySelector('#submit-button');
    const originalText = submitButton.textContent;
    submitButton.disabled = true;
    submitButton.textContent = 'Submitting...';

    try {
        // Submit form
        const response = await fetch(form.action, {
            method: form.method,
            body: formData
        });

        // Check if submission was successful
        if (response.ok) {
            showFormMessage('Form submitted successfully! Thank you for your submission.', 'success');
            // Optionally reset form after successful submission
            // form.reset();
        } else {
            throw new Error('Server error during submission');
        }
    } catch (error) {
        console.error('Form submission error:', error);
        showFormMessage('An error occurred while submitting the form. Please try again or contact support.', 'error');
        throw error;
    } finally {
        // Restore button state
        submitButton.disabled = false;
        submitButton.textContent = originalText;
    }
}

