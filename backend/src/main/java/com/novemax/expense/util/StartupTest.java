package com.novemax.expense.util;


import com.novemax.expense.config.CustomDatasourceProperties;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.boot.CommandLineRunner;
import org.springframework.stereotype.Component;

@Component
public class StartupTest implements CommandLineRunner {

  private final CustomDatasourceProperties customDatasourceProperties;

  public StartupTest(CustomDatasourceProperties customDatasourceProperties) {
    this.customDatasourceProperties = customDatasourceProperties;
  }

  @Override
  public void run(String... args) {
    System.out.println("Senha descriptografada: " + customDatasourceProperties.getPassword());
  }
}

