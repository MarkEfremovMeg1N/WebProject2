// Обновленный JavaScript - форма остается видимой после отправки
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('supportForm');
  const submitBtn = document.getElementById('submitBtn');
  const btnText = submitBtn.querySelector('.btn-text');
  const btnLoader = submitBtn.querySelector('.btn-loader');
  const formMessages = document.getElementById('form-messages');
  
  // Функция для отображения сообщений
  function showMessage(message, type = 'error') {
    formMessages.textContent = message;
    formMessages.className = 'form-messages ' + type;
    formMessages.style.display = 'block';
    
    // Автоматическое скрытие сообщения через 5 секунд
    setTimeout(() => {
      formMessages.style.display = 'none';
    }, 5000);
  }
  
  // Функция для сброса состояния кнопки
  function resetButtonState() {
    submitBtn.disabled = false;
    btnText.style.display = 'inline';
    btnLoader.style.display = 'none';
  }
  
  // Функция для сброса полей формы (опционально)
  function resetFormFields() {
    form.reset();
    // Восстанавливаем стили placeholder для всех полей
    const inputs = form.querySelectorAll('input, textarea');
    inputs.forEach(input => {
      input.dispatchEvent(new Event('blur'));
    });
  }
  
  // Обработчик отправки формы
  form.addEventListener('submit', async function(event) {
    event.preventDefault(); // Предотвращаем стандартную отправку
    
    // Проверяем согласие на обработку данных
    const agreement = document.getElementById('agreement');
    if (!agreement.checked) {
      showMessage('Пожалуйста, дайте согласие на обработку персональных данных');
      return;
    }
    
    // Показываем индикатор загрузки
    submitBtn.disabled = true;
    btnText.style.display = 'none';
    btnLoader.style.display = 'inline';
    formMessages.style.display = 'none';
    
    // Собираем данные формы
    const formData = new FormData(form);
    
    try {
      // Отправляем данные на Formcarry
      const response = await fetch(form.action, {
        method: 'POST',
        body: formData,
        headers: {
          'Accept': 'application/json'
        }
      });
      
      const result = await response.json();
      
      if (result.code === 200) {
        // Успешная отправка - показываем сообщение и сбрасываем форму
        showMessage('Спасибо! Ваша заявка отправлена. Мы свяжемся с вами в ближайшее время.', 'success');
        resetFormFields();
        resetButtonState();
        
        // Прокручиваем к сообщению об успехе
        formMessages.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      } else {
        // Ошибка от Formcarry
        showMessage(result.message || 'Произошла ошибка при отправке формы');
        resetButtonState();
      }
    } catch (error) {
      // Ошибка сети или другая ошибка
      console.error('Ошибка отправки формы:', error);
      showMessage('Произошла ошибка при отправке формы. Пожалуйста, попробуйте еще раз.');
      resetButtonState();
    }
  });
  
  // Валидация телефона (опционально)
  const phoneInput = document.getElementById('phone');
  phoneInput.addEventListener('input', function() {
    // Очищаем от всех символов, кроме цифр, +, - и пробелов
    this.value = this.value.replace(/[^\d\+\-\s]/g, '');
  });
  
  // Валидация email (опционально)
  const emailInput = document.getElementById('email');
  emailInput.addEventListener('blur', function() {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (this.value && !emailRegex.test(this.value)) {
      showMessage('Пожалуйста, введите корректный email адрес');
    }
  });
  
  // Автоматически показываем лейблы при фокусе
  const formGroups = form.querySelectorAll('.form-group');
  formGroups.forEach(group => {
    const input = group.querySelector('input, textarea');
    const label = group.querySelector('label');
    
    input.addEventListener('focus', () => {
      label.style.top = '8px';
      label.style.fontSize = '12px';
      label.style.opacity = '0.7';
    });
    
    input.addEventListener('blur', () => {
      if (!input.value) {
        label.style.top = '20px';
        label.style.fontSize = '16px';
        label.style.opacity = '1';
      }
    });
  });
});