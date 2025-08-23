function extractTitle(content) {
    // Chercher le pattern label="XXX"
    const labelMatch = content.match(/label="([^"]+)"/);
    if (labelMatch && labelMatch[1]) {
        return labelMatch[1];
    }
    
    // Si pas de label, retourner la première ligne nettoyée
    const lines = content.split('\n');
    return lines[0].trim();
}

document.addEventListener('DOMContentLoaded', function() {
    const pluginWrapper = document.querySelector('.planning-danse-plugin-wrapper');
    if (!pluginWrapper) {
        // Si le plugin n'est pas sur la page, on ne fait rien.
        return;
    }

    const cart = {
        items: [],
        maxItems: 3,
        
        // Nouvelle méthode pour extraire l'âge d'un cours
        getAgeCategory(courseTitle) {
            const lines = courseTitle.split('\n');
            const ageLine = lines.find(l => {
                const lower = l.toLowerCase();
                return lower.includes('enfant') || 
                       lower.includes('adulte') || 
                       lower.includes('ado');
            });
            
            if (ageLine) {
                if (ageLine.toLowerCase().includes('enfant')) return 'ENFANT';
                if (ageLine.toLowerCase().includes('adulte')) return 'ADULTE';
                if (ageLine.toLowerCase().includes('ado')) return 'ADO';
            }
            return null;
        },
    
        // Nouvelle méthode pour vérifier la compatibilité des âges
        validateAgeCompatibility() {
            if (this.items.length === 0) return { valid: true };
    
            const ages = this.items.map(item => this.getAgeCategory(item.title));
            const uniqueAges = [...new Set(ages.filter(age => age !== null))];
            
            if (uniqueAges.length > 1) {
                return {
                    valid: false,
                    message: `Vous ne pouvez pas mélanger différentes catégories d'âge (${uniqueAges.join(', ')}) dans votre sélection.`
                };
            }
            
            return { valid: true };
        },
        
        addItem(course) {
            if (this.items.length >= this.maxItems) {
                alert('Vous ne pouvez sélectionner que 3 cours maximum');
                return false;
            }
            
            if (this.items.some(item => 
                item.title === course.title && 
                item.day === course.day && 
                item.time === course.time)) {
                return false;
            }
    
            // Vérifier la compatibilité des âges avant d'ajouter
            const newAgeCategory = this.getAgeCategory(course.title);
            if (this.items.length > 0 && newAgeCategory) {
                const existingAges = this.items.map(item => this.getAgeCategory(item.title))
                                            .filter(age => age !== null);
                
                if (existingAges.length > 0 && !existingAges.includes(newAgeCategory)) {
                    alert(`Vous ne pouvez pas mélanger des cours de différentes catégories d'âge.\nVous avez déjà sélectionné un cours pour ${existingAges[0]}.`);
                    return false;
                }
            }
            
            this.items.push(course);
            this.updateDisplay();
            this.updateCartToggle();
            const cartElement = pluginWrapper.querySelector('.booking-cart');
            if(cartElement) cartElement.classList.add('open');
            return true;
        },
        
        updateDisplay() {
            const container = pluginWrapper.querySelector('.cart-items');
            if(!container) return;
            container.innerHTML = '';
            
            this.items.forEach((item, index) => {
                const courseTitle = extractTitle(item.title);
                const category = courseTitle.trim().split(' ')[0].toLowerCase();
                
                const lines = item.title.split('\n');
                const age = lines.find(l => 
                    l.toLowerCase().includes('adulte') || 
                    l.toLowerCase().includes('enfant') || 
                    l.toLowerCase().includes('ado')
                );
                const hasNoSpectacle = lines.some(l => 
                    l.toLowerCase().includes('no spectacle')
                );
        
                const div = document.createElement('div');
                div.className = 'cart-item';
                
                div.innerHTML = `
                    <h4>${courseTitle}</h4>
                    <p>le ${item.day} ${item.time}</p>
                    ${age ? `<p>Age : ${age}</p>` : ''}
                    ${item.teacher ? `<p>Prof: ${item.teacher}</p>` : ''}
                    ${hasNoSpectacle ? `<p class="no-spectacle-text">${window.planningSettings?.noSpectacleText || 'Cours non concerné par le spectacle'}</p>` : ''}
                    <button class="remove-item" data-index="${index}">&times;</button>
                `;
                
                div.classList.add(`cart-item-${category}`);
                container.appendChild(div);
            });
            
            const cartTotal = pluginWrapper.querySelector('.cart-total');
            if(cartTotal) cartTotal.textContent = `${this.items.length}/3 cours sélectionnés`;
                
            const validateButton = pluginWrapper.querySelector('.validate-booking');
            if(validateButton) {
                const ageValidation = this.validateAgeCompatibility();
                validateButton.disabled = this.items.length === 0 || !ageValidation.valid;
                if (!ageValidation.valid) {
                    validateButton.title = ageValidation.message;
                } else {
                    validateButton.title = '';
                }
            }
            this.updateCartToggle();
        },
        
        updateCartToggle() {
            const toggleBtn = pluginWrapper.querySelector('.booking-cart-toggle');
            if(!toggleBtn) return;

            let cartCountDisplay = toggleBtn.querySelector('.cart-count');
            
            if (!cartCountDisplay) {
                const countSpan = document.createElement('span');
                countSpan.className = 'cart-count';
                toggleBtn.appendChild(countSpan);
                cartCountDisplay = countSpan;
            }
            
            if (cartCountDisplay) {
                cartCountDisplay.textContent = this.items.length;
            }
            
            const cartElement = pluginWrapper.querySelector('.booking-cart');
            if(cartElement) {
               toggleBtn.style.display = cartElement.classList.contains('open') ? 'none' : 'flex';
            }
        },
        
        removeItem(index) {
            const removedItem = this.items[index];
            this.items.splice(index, 1);
            this.updateDisplay();
            this.updateCartToggle();
            
            pluginWrapper.querySelectorAll('.book-trial.selected').forEach(button => {
                const buttonData = JSON.parse(button.dataset.course);
                if (buttonData.title === removedItem.title && 
                    buttonData.day === removedItem.day && 
                    buttonData.time === removedItem.time) {
                    button.classList.remove('selected');
                    button.textContent = 'Essayer';
                }
            });
        },
        
        clear() {
            this.items = [];
            this.updateDisplay();
            this.updateCartToggle();
            pluginWrapper.querySelectorAll('.book-trial.selected').forEach(button => {
                button.classList.remove('selected');
                button.textContent = 'Essayer';
            });
        }
    };

    // Gestionnaire des boutons "Essayer"
    pluginWrapper.addEventListener('click', function(e) {
        if (e.target.matches('.book-trial')) {
            const courseData = JSON.parse(e.target.dataset.course);
            if (cart.addItem(courseData)) {
                e.target.classList.add('selected');
                e.target.textContent = 'Sélectionné';
            }
        }
        
        if (e.target.matches('.remove-item')) {
            cart.removeItem(parseInt(e.target.dataset.index));
        }
    });

    // Gestion du panier
    const toggleButton = pluginWrapper.querySelector('.booking-cart-toggle');
    if (toggleButton) {
        toggleButton.addEventListener('click', function() {
            const cartEl = pluginWrapper.querySelector('.booking-cart');
            if(cartEl) cartEl.classList.add('open');
            this.style.display = 'none';
        });
    }

    const closeButton = pluginWrapper.querySelector('.close-cart');
    if(closeButton) {
        closeButton.addEventListener('click', function() {
            const cartEl = pluginWrapper.querySelector('.booking-cart');
            const toggleBtn = pluginWrapper.querySelector('.booking-cart-toggle');
            if(cartEl) cartEl.classList.remove('open');
            if(toggleBtn) toggleBtn.style.display = 'flex';
        });
    }

    // Gestion du formulaire de réservation
    const validateButton = pluginWrapper.querySelector('.validate-booking');
    if (validateButton) {
        validateButton.addEventListener('click', function() {
            const ageValidation = cart.validateAgeCompatibility();
            if (!ageValidation.valid) {
                alert(ageValidation.message);
                return;
            }
            const modal = pluginWrapper.querySelector('#booking-form-modal');
            if(!modal) return;

            modal.style.display = 'block';
            setTimeout(() => modal.classList.add('show'), 10);
            
            const recap = modal.querySelector('.selected-courses');
            recap.innerHTML = cart.items.map(item => {
                const courseTitle = extractTitle(item.title);
                const category = courseTitle.trim().split(' ')[0].toLowerCase();
                
                const lines = item.title.split('\n');
                const age = lines.find(l => 
                    l.toLowerCase().includes('adulte') || 
                    l.toLowerCase().includes('enfant') || 
                    l.toLowerCase().includes('ado')
                );
                const hasNoSpectacle = lines.some(l => 
                    l.toLowerCase().includes('no spectacle')
                );
                
                return `
                    <div class="selected-course cart-item-${category}">
                        <h4>${courseTitle}</h4>
                        <p>le ${item.day} ${item.time}</p>
                        ${age ? `<p>Age : ${age}</p>` : ''}
                        ${item.teacher ? `<p>Prof: ${item.teacher}</p>` : ''}
                        ${hasNoSpectacle ? `<p class="no-spectacle-text">${window.planningSettings?.noSpectacleText || 'Cours non concerné par le spectacle'}</p>` : ''}
                    </div>
                `;
            }).join('');
        });
    }
    
    // Fermeture de la modal
    const modal = pluginWrapper.querySelector('#booking-form-modal');
    if (modal) {
        window.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('show');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            }
        });
    }

    // Soumission du formulaire
    const bookingForm = pluginWrapper.querySelector('#trial-booking-form');
    if(bookingForm) {
        bookingForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const formValues = {};
            formData.forEach((value, key) => {
                formValues[key] = value;
            });
        
            try {
                const response = await fetch(planningAjax.ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'submit_trial_booking',
                        nonce: planningAjax.nonce,
                        courses: JSON.stringify(cart.items),
                        form: JSON.stringify(formValues)
                    })
                });
                
                if (response.ok) {
                    alert('Votre réservation a été envoyée avec succès !');
                    cart.clear();
                    if(modal) modal.style.display = 'none';
                    const cartEl = pluginWrapper.querySelector('.booking-cart');
                    if(cartEl) cartEl.classList.remove('open');
                } else {
                    throw new Error('Erreur lors de l\'envoi');
                }
            } catch (error) {
                alert('Une erreur est survenue lors de l\'envoi de votre réservation.');
            }
        });
    }

    // Initialisation du filtre des jours
    function initDaysFilter() {
        const daysFilter = pluginWrapper.querySelector('.days-filter');
        if (!daysFilter) return;
    
        const defaultActiveStates = {
            'lundi': true, 'mardi': true, 'mercredi': true,
            'jeudi': true, 'vendredi': true, 'samedi': true
        };
    
        let activeStates = JSON.parse(localStorage.getItem('planningDaysFilter')) || defaultActiveStates;
    
        Object.keys(defaultActiveStates).forEach(day => {
            if (typeof activeStates[day] === 'undefined') {
                activeStates[day] = true;
            }
        });
    
        function updateColumns() {
            const planningCells = pluginWrapper.querySelectorAll('.planning-table [data-day]');
            
            Object.entries(activeStates).forEach(([day, isActive]) => {
                planningCells.forEach(cell => {
                    if (cell.getAttribute('data-day') === day) {
                        if (isActive) {
                            cell.style.display = '';
                            cell.classList.remove('hidden-day');
                        } else {
                            cell.style.display = 'none';
                            cell.classList.add('hidden-day');
                        }
                    }
                });
                
                const dayButton = pluginWrapper.querySelector(`.day-toggle[data-day="${day}"]`);
                if (dayButton) {
                    dayButton.classList.toggle('active', isActive);
                }
            });
            
            localStorage.setItem('planningDaysFilter', JSON.stringify(activeStates));
        }
    
        daysFilter.querySelectorAll('.day-toggle').forEach(toggle => {
            const day = toggle.dataset.day;
            if (typeof activeStates[day] !== 'undefined') {
                toggle.classList.toggle('active', activeStates[day]);
            } else {
                activeStates[day] = true;
                toggle.classList.add('active');
            }
        });
    
        daysFilter.addEventListener('click', (e) => {
            const toggle = e.target.closest('.day-toggle');
            if (!toggle) return;
    
            e.preventDefault();
            
            const day = toggle.dataset.day;
            
            const activeCount = Object.values(activeStates).filter(Boolean).length;
            if (activeStates[day] && activeCount <= 1) {
                alert('Au moins un jour doit rester visible');
                return;
            }
    
            activeStates[day] = !activeStates[day];
            toggle.classList.toggle('active');
    
            updateColumns();
        });
    
        updateColumns();
    }

    initDaysFilter();
});
