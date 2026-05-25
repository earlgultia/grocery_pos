// js/main.js

// Security: Prevent XSS and injection
function sanitizeInput(input) {
    const div = document.createElement('div');
    div.textContent = input;
    return div.innerHTML;
}

// Security: Validate email format
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Security: Validate password strength
function validatePassword(password) {
    const minLength = 8;
    const hasUpperCase = /[A-Z]/.test(password);
    const hasLowerCase = /[a-z]/.test(password);
    const hasNumbers = /\d/.test(password);
    const hasSpecialChar = /[!@#$%^&*(),.?":{}|<>]/.test(password);
    
    return {
        isValid: password.length >= minLength && hasUpperCase && hasLowerCase && hasNumbers && hasSpecialChar,
        errors: {
            minLength: password.length >= minLength,
            hasUpperCase: hasUpperCase,
            hasLowerCase: hasLowerCase,
            hasNumbers: hasNumbers,
            hasSpecialChar: hasSpecialChar
        }
    };
}

// Security: Rate limiting for form submissions
class RateLimiter {
    constructor(limit = 5, windowMs = 60000) {
        this.limit = limit;
        this.windowMs = windowMs;
        this.requests = new Map();
    }
    
    checkLimit(key) {
        const now = Date.now();
        const userRequests = this.requests.get(key) || [];
        const validRequests = userRequests.filter(time => now - time < this.windowMs);
        
        if (validRequests.length >= this.limit) {
            return false;
        }
        
        validRequests.push(now);
        this.requests.set(key, validRequests);
        return true;
    }
}

const rateLimiter = new RateLimiter();

// Mobile menu toggle
function toggleMobileMenu() {
    const navMenu = document.querySelector('.nav-menu');
    const btn = document.querySelector('.mobile-menu-btn');
    
    if (navMenu.style.display === 'flex') {
        navMenu.style.display = 'none';
        btn.classList.remove('active');
    } else {
        navMenu.style.display = 'flex';
        navMenu.style.flexDirection = 'column';
        navMenu.style.position = 'absolute';
        navMenu.style.top = '70px';
        navMenu.style.left = '0';
        navMenu.style.right = '0';
        navMenu.style.backgroundColor = 'white';
        navMenu.style.padding = '1rem';
        navMenu.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1)';
        btn.classList.add('active');
    }
}

// Animated counter for statistics
function animateCounter(element, target) {
    let current = 0;
    const increment = target / 50;
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            element.textContent = target;
            clearInterval(timer);
        } else {
            element.textContent = Math.floor(current);
        }
    }, 20);
}

// Initialize counters when they come into view
const observerOptions = {
    threshold: 0.5
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const element = entry.target;
            const target = parseInt(element.getAttribute('data-count'));
            animateCounter(element, target);
            observer.unobserve(element);
        }
    });
}, observerOptions);

// Observe all stat numbers
document.querySelectorAll('.stat-number').forEach(stat => {
    observer.observe(stat);
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Form validation and CSRF protection
class FormSecurity {
    static addCSRFToken(form) {
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
        form.appendChild(csrfInput);
    }
    
    static sanitizeFormData(formData) {
        const sanitized = {};
        for (let [key, value] of formData.entries()) {
            sanitized[key] = sanitizeInput(value);
        }
        return sanitized;
    }
    
    static validateForm(form) {
        const inputs = form.querySelectorAll('input, textarea, select');
        let isValid = true;
        
        inputs.forEach(input => {
            if (input.hasAttribute('required') && !input.value.trim()) {
                this.showError(input, 'This field is required');
                isValid = false;
            }
            
            if (input.type === 'email' && input.value && !validateEmail(input.value)) {
                this.showError(input, 'Please enter a valid email address');
                isValid = false;
            }
            
            const requiresStrongPassword = input.dataset.passwordPolicy === 'strong' || input.closest('form')?.dataset.passwordPolicy === 'strong';

            if (input.type === 'password' && input.value && requiresStrongPassword) {
                const passwordCheck = validatePassword(input.value);
                if (!passwordCheck.isValid) {
                    this.showError(input, 'Password must be at least 8 characters with uppercase, lowercase, number, and special character');
                    isValid = false;
                }
            }
        });
        
        return isValid;
    }
    
    static showError(input, message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        errorDiv.textContent = message;
        errorDiv.style.color = '#EF4444';
        errorDiv.style.fontSize = '0.875rem';
        errorDiv.style.marginTop = '0.25rem';
        
        // Remove existing error
        const existingError = input.parentElement.querySelector('.error-message');
        if (existingError) existingError.remove();
        
        input.parentElement.appendChild(errorDiv);
        input.style.borderColor = '#EF4444';
        
        setTimeout(() => {
            errorDiv.remove();
            input.style.borderColor = '';
        }, 3000);
    }
}

// Add loading state to buttons
function addLoadingState(button) {
    const originalText = button.textContent;
    button.disabled = true;
    button.innerHTML = '<span class="loading"></span> Loading...';
    
    return function reset() {
        button.disabled = false;
        button.textContent = originalText;
    };
}

// Handle form submissions securely
document.addEventListener('DOMContentLoaded', () => {
    // Add CSRF protection to all forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        if (!form.querySelector('input[name="csrf_token"]')) {
            FormSecurity.addCSRFToken(form);
        }
        
        form.addEventListener('submit', async (e) => {
            if (!FormSecurity.validateForm(form)) {
                e.preventDefault();
                return;
            }
            
            // Rate limiting check
            const formId = form.id || 'default_form';
            if (!rateLimiter.checkLimit(formId)) {
                e.preventDefault();
                alert('Too many attempts. Please try again later.');
                return;
            }
            
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                const resetButton = addLoadingState(submitButton);
                // Reset after form submission (in real implementation, this would be after response)
                setTimeout(resetButton, 3000);
            }
        });
    });
    
    // Add input validation on blur
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.addEventListener('blur', () => {
            if (input.hasAttribute('required') && !input.value.trim()) {
                FormSecurity.showError(input, 'This field is required');
            }
            
            if (input.type === 'email' && input.value && !validateEmail(input.value)) {
                FormSecurity.showError(input, 'Please enter a valid email address');
            }
        });
        
        // Remove error styling on input
        input.addEventListener('input', () => {
            input.style.borderColor = '';
            const error = input.parentElement.querySelector('.error-message');
            if (error) error.remove();
        });
    });
});

// Password visibility toggles (shared)
function initPasswordToggles() {
    const eyeOpenIcon = `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"></path>
            <circle cx="12" cy="12" r="3"></circle>
        </svg>
    `;

    const eyeOffIcon = `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M3 3l18 18"></path>
            <path d="M10.58 10.58A2 2 0 0 0 12 14a2 2 0 0 0 1.42-.58"></path>
            <path d="M9.88 5.09A9.77 9.77 0 0 1 12 5c7 0 11 7 11 7a20.2 20.2 0 0 1-3.24 4.19"></path>
            <path d="M6.61 6.61C3.62 8.44 1 12 1 12a20.3 20.3 0 0 0 7.39 5.39"></path>
        </svg>
    `;

    document.querySelectorAll('.toggle-password').forEach(button => {
        button.innerHTML = eyeOpenIcon;
        button.setAttribute('aria-label', 'Show password');

        button.addEventListener('click', () => {
            const targetId = button.dataset.target;
            const input = document.getElementById(targetId);
            if (!input) return;
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            button.classList.toggle('is-visible', isPassword);
            button.innerHTML = isPassword ? eyeOffIcon : eyeOpenIcon;
            button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
    });
}

// Initialize password toggles on load
document.addEventListener('DOMContentLoaded', initPasswordToggles);

// Landing page mobile nav toggle
function initLandingNavToggle() {
    const toggleButton = document.querySelector('.landing-nav-toggle');
    const navMenu = document.querySelector('.landing-nav');

    if (!toggleButton || !navMenu) {
        return;
    }

    const toggleText = toggleButton.querySelector('.landing-nav-toggle-text');
    const mobileQuery = window.matchMedia('(max-width: 992px)');

    const setState = (isOpen) => {
        navMenu.classList.toggle('is-open', isOpen);
        toggleButton.classList.toggle('is-open', isOpen);
        if (isOpen) {
            document.documentElement.classList.add('landing-nav-open');
            document.documentElement.style.overflow = 'hidden';
        } else {
            document.documentElement.classList.remove('landing-nav-open');
            document.documentElement.style.overflow = '';
        }
        document.body.style.overflow = '';
        toggleButton.setAttribute('aria-expanded', String(isOpen));

        if (toggleText) {
            toggleText.textContent = isOpen ? 'Close' : 'Menu';
        }
    };

    setState(false);

    toggleButton.addEventListener('click', () => {
        setState(!navMenu.classList.contains('is-open'));
    });

    navMenu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (mobileQuery.matches) {
                setState(false);
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!mobileQuery.matches || !navMenu.classList.contains('is-open')) {
            return;
        }

        if (!toggleButton.contains(event.target) && !navMenu.contains(event.target)) {
            setState(false);
        }
    });

    window.addEventListener('resize', () => {
        if (!mobileQuery.matches) {
            setState(false);
        }
    });
}

document.addEventListener('DOMContentLoaded', initLandingNavToggle);

// Lazy load images for performance
const imageObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const img = entry.target;
            const src = img.getAttribute('data-src');
            if (src) {
                img.src = src;
                img.removeAttribute('data-src');
            }
            imageObserver.unobserve(img);
        }
    });
});

document.querySelectorAll('img[data-src]').forEach(img => {
    imageObserver.observe(img);
});

// Add scroll effect to navbar
let lastScroll = 0;
window.addEventListener('scroll', () => {
    const navbar = document.querySelector('.navbar');
    if (!navbar) {
        return;
    }
    const currentScroll = window.pageYOffset;
    
    if (currentScroll > lastScroll && currentScroll > 100) {
        navbar.style.transform = 'translateY(-100%)';
    } else {
        navbar.style.transform = 'translateY(0)';
    }
    
    lastScroll = currentScroll;
});

// Console security - prevent tampering
if (window.console) {
    const originalConsole = window.console;
    window.console = {
        ...originalConsole,
        log: function() {
            if (window.location.hostname !== 'localhost') {
                return;
            }
            originalConsole.log.apply(console, arguments);
        }
    };
}
