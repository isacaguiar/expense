package com.novemax.expense.util;

import lombok.Value;
import org.springframework.boot.CommandLineRunner;
import org.springframework.stereotype.Component;

@Component
public class StartupTest implements CommandLineRunner {

  @Value("${spring.datasource.password}")
  private String password;

  @Override
  public void run(String... args) {
    System.out.println("Senha descriptografada: " + password);
  }
}
