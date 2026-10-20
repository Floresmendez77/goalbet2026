describe('Autenticación, Rate Limiting y Control de Acceso (RBAC)', () => {
  const timestamp = Date.now();
  const testUser = `user_${timestamp}`;
  const testEmail = `user_${timestamp}@goalbet.test`;

  beforeEach(() => {
    cy.viewport(1280, 720);
  });

  it('1. Debe registrar un nuevo usuario validando la confirmación +18 y redirigir a login', () => {
    cy.visit('/register.php');
    cy.contains('h1', 'Crear cuenta').should('be.visible');

    cy.get('[data-cy="input-nombre"]').type('Nuevo');
    cy.get('[data-cy="input-apellido"]').type('Usuario');
    cy.get('[data-cy="input-username"]').type(testUser);
    cy.get('[data-cy="input-email"]').type(testEmail);
    cy.get('[data-cy="input-password"]').type('Password123!');
    cy.get('[data-cy="input-password2"]').type('Password123!');
    cy.get('[data-cy="check-mayor-edad"]').check();

    cy.get('[data-cy="btn-register"]').click();

    cy.url().should('include', '/login.php');
    cy.get('.alert-success').should('be.visible').and('contain.text', 'Cuenta creada');
  });

  it('2. Debe bloquear por rate limit tras 5 intentos fallidos consecutivos de login', () => {
    cy.visit('/login.php');
    const attackerUser = `rate_target_${timestamp}`;

    // Intentos 1 a 4: advertencia con intentos restantes
    for (let intento = 1; intento <= 4; intento++) {
      cy.get('[data-cy="input-identificador"]').clear().type(attackerUser);
      cy.get('[data-cy="input-password"]').clear().type('WrongPass999!');
      cy.get('[data-cy="btn-login"]').click();

      cy.get('[data-cy="login-error"]')
        .should('be.visible')
        .and('contain.text', 'incorrectos')
        .and('contain.text', `${5 - intento} intento(s)`);
    }

    // Intento 5: bloqueo temporal por 15 minutos
    cy.get('[data-cy="input-identificador"]').clear().type(attackerUser);
    cy.get('[data-cy="input-password"]').clear().type('WrongPass999!');
    cy.get('[data-cy="btn-login"]').click();

    cy.get('[data-cy="login-error"]')
      .should('be.visible')
      .and('contain.text', 'bloqueado temporalmente por 15 minutos');

    // Intento 6: sigue bloqueado de inmediato
    cy.get('[data-cy="input-identificador"]').clear().type(attackerUser);
    cy.get('[data-cy="input-password"]').clear().type('WrongPass999!');
    cy.get('[data-cy="btn-login"]').click();

    cy.get('[data-cy="login-error"]')
      .should('be.visible')
      .and('contain.text', 'bloqueado temporalmente por 15 minutos');
  });

  it('3. Debe denegar el acceso a /admin a un usuario con rol de cliente normal (RBAC)', () => {
    // Iniciar sesión con cliente normal
    cy.visit('/login.php');
    cy.get('[data-cy="input-identificador"]').clear().type('qa_cliente');
    cy.get('[data-cy="input-password"]').clear().type('Password123!');
    cy.get('[data-cy="btn-login"]').click();
    cy.url().should('include', '/dashboard.php');

    // Intentar ingresar a la sección de administración
    cy.visit('/admin/index.php', { failOnStatusCode: false });

    // Debe ser redirigido a dashboard y mostrar mensaje de permisos insuficientes
    cy.url().should('include', '/dashboard.php');
    cy.get('.alert-danger')
      .should('be.visible')
      .and('contain.text', 'No tenés permisos para acceder a esa sección');
  });
});
